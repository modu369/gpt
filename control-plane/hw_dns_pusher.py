# hw_dns_pusher.py - 华为云 DNS 批量更新工具
import json
import os
import sys

from huaweicloudsdkcore.auth.credentials import BasicCredentials
from huaweicloudsdkdns.v2 import (
    BatchCreateRecordSetsTaskItem,
    BatchCreateRecordSetsTaskRequest,
    BatchCreateRecordSetsTaskRequestBody,
    BatchDeleteRecordSetWithLineRequest,
    BatchDeleteRecordSetWithLineRequestBody,
    DnsClient,
    ListRecordSetsByZoneRequest,
)
from huaweicloudsdkdns.v2.region.dns_region import DnsRegion


def main() -> None:
    if len(sys.argv) < 2:
        print("Error: No config file")
        return

    with open(sys.argv[1], "r", encoding="utf-8") as f:
        config = json.load(f)

    ak = os.environ.get("CLOUD_SDK_AK", "")
    sk = os.environ.get("CLOUD_SDK_SK", "")
    zone_id = config.get("zone_id", "")
    region_str = config.get("region", "ap-southeast-1")
    records = config.get("records", [])

    if not ak or not sk or not zone_id:
        print("SDK Error: missing AK/SK/ZONE_ID")
        return

    credentials = BasicCredentials(ak, sk)
    client = (
        DnsClient.new_builder()
        .with_credentials(credentials)
        .with_region(DnsRegion.value_of(region_str))
        .build()
    )

    try:
        list_req = ListRecordSetsByZoneRequest(zone_id=zone_id)
        list_resp = client.list_record_sets_by_zone(list_req)

        ids_to_delete = []
        target_name_prefix = records[0]["name"] if records else ""
        for r in list_resp.recordsets:
            if target_name_prefix and target_name_prefix in r.name and r.type == "A":
                ids_to_delete.append(r.id)

        if ids_to_delete:
            del_req = BatchDeleteRecordSetWithLineRequest(zone_id=zone_id)
            del_req.body = BatchDeleteRecordSetWithLineRequestBody(recordset_ids=ids_to_delete)
            client.batch_delete_record_set_with_line(del_req)
            print(f"Deleted {len(ids_to_delete)} old records.")

        if records:
            create_req = BatchCreateRecordSetsTaskRequest(zone_id=zone_id)
            body_list = []
            for item in records:
                body_list.append(
                    BatchCreateRecordSetsTaskItem(
                        name=item["name"],
                        type="A",
                        ttl=item.get("ttl", 1),
                        weight=item.get("weight", 1),
                        records=item.get("records", []),
                        status="ENABLE",
                    )
                )

            create_req.body = BatchCreateRecordSetsTaskRequestBody(recordsets=body_list)
            resp = client.batch_create_record_sets_task(create_req)
            print(f"Created task: {resp.task_id}")

    except Exception as e:
        print(f"SDK Error: {str(e)}")


if __name__ == "__main__":
    main()
