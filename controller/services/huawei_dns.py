"""Huawei Cloud DNS (intl) integration.

If official Huawei SDK packages are installed and AK/SK provided,
this module will perform real recordset upsert.
Otherwise it falls back to dry-run mode.
"""

from dataclasses import dataclass
from typing import List, Tuple


@dataclass
class NodeWeight:
    ip: str
    weight: int


def compute_weight(cpu_percent: float, mem_percent: float, bw_percent: float) -> int:
    """Higher free resource => higher weight.

    Weight range: 1~100
    """
    pressure = max(cpu_percent, mem_percent, bw_percent)
    free_score = max(0.0, 100.0 - pressure)
    return max(1, min(100, int(round(free_score))))


def _weighted_addresses(targets: List[NodeWeight]) -> List[str]:
    # Huawei DNS weighted round-robin can be modeled by repeated value entries.
    # keep list short but proportional.
    rows: List[str] = []
    for t in targets:
        repeats = max(1, int(round(t.weight / 10)))
        rows.extend([t.ip] * repeats)
    return rows


def update_weighted_records(
    ak: str,
    sk: str,
    region: str,
    zone_id: str,
    recordset_name: str,
    ttl: int,
    targets: List[NodeWeight],
) -> Tuple[bool, str]:
    if not targets:
        return False, "no available targets"

    try:
        from huaweicloudsdkcore.auth.credentials import BasicCredentials
        from huaweicloudsdkdns.v2 import DnsClient
        from huaweicloudsdkdns.v2.region.dns_region import DnsRegion
        from huaweicloudsdkdns.v2.model.list_record_sets_with_line_request import (
            ListRecordSetsWithLineRequest,
        )
        from huaweicloudsdkdns.v2.model.create_record_set_with_line_request import (
            CreateRecordSetWithLineRequest,
        )
        from huaweicloudsdkdns.v2.model.update_record_set_with_line_request import (
            UpdateRecordSetWithLineRequest,
        )
        from huaweicloudsdkdns.v2.model.create_record_set_with_line_req import (
            CreateRecordSetWithLineReq,
        )
        from huaweicloudsdkdns.v2.model.update_record_set_with_line_req import (
            UpdateRecordSetWithLineReq,
        )
    except Exception:
        rows = _weighted_addresses(targets)
        return True, f"dry-run: {recordset_name} -> {rows}"

    if not (ak and sk and zone_id and recordset_name):
        rows = _weighted_addresses(targets)
        return True, f"dry-run(missing ak/sk/zone): {recordset_name} -> {rows}"

    credentials = BasicCredentials(ak, sk)
    client = DnsClient.new_builder().with_credentials(credentials).with_region(DnsRegion.value_of(region)).build()
    rows = _weighted_addresses(targets)

    list_req = ListRecordSetsWithLineRequest(zone_id=zone_id, name=recordset_name, type="A")
    existing = client.list_record_sets_with_line(list_req)
    if existing.recordsets:
        rid = existing.recordsets[0].id
        update_req = UpdateRecordSetWithLineRequest(
            zone_id=zone_id,
            recordset_id=rid,
            body=UpdateRecordSetWithLineReq(
                name=recordset_name,
                type="A",
                ttl=ttl,
                records=rows,
            ),
        )
        client.update_record_set_with_line(update_req)
        return True, f"updated {recordset_name} ({len(rows)} records)"

    create_req = CreateRecordSetWithLineRequest(
        zone_id=zone_id,
        body=CreateRecordSetWithLineReq(
            name=recordset_name,
            type="A",
            ttl=ttl,
            records=rows,
        ),
    )
    client.create_record_set_with_line(create_req)
    return True, f"created {recordset_name} ({len(rows)} records)"
