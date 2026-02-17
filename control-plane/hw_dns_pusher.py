# -*- coding: utf-8 -*-
# hw_dns_pusher.py V9.3 - Safe Attribute Access
import sys
import json
import os
from huaweicloudsdkcore.auth.credentials import BasicCredentials
from huaweicloudsdkcore.exceptions import exceptions
from huaweicloudsdkdns.v2 import DnsClient
from huaweicloudsdkdns.v2.region.dns_region import DnsRegion
from huaweicloudsdkdns.v2.model import (
    ListRecordSetsByZoneRequest,
    CreateRecordSetRequest,
    CreateRecordSetRequestBody,
    UpdateRecordSetRequest,
    UpdateRecordSetReq
)

def main():
    print("--- DNS Pusher V9.3 (Stable) ---")
    
    if len(sys.argv) < 2: return
    
    ak = os.environ.get('CLOUD_SDK_AK')
    sk = os.environ.get('CLOUD_SDK_SK')
    if not ak or not sk:
        print("Error: AK/SK not found")
        sys.exit(1)

    try:
        with open(sys.argv[1], 'r', encoding='utf-8') as f:
            data = json.load(f)
    except: return

    zone_id = data.get('zone_id')
    region_id = data.get('region')
    target_name = data.get('record_name')
    target_ips = sorted(data.get('record_ips', []))
    target_ttl = int(data.get('ttl', 1))

    if not target_name.endswith('.'): target_name += '.'

    credentials = BasicCredentials(ak, sk)
    client = DnsClient.new_builder() \
        .with_credentials(credentials) \
        .with_region(DnsRegion.value_of(region_id)) \
        .build()

    # 1. 查询现有记录
    existing_record = None
    try:
        list_req = ListRecordSetsByZoneRequest()
        list_req.zone_id = zone_id
        list_req.name = target_name
        list_req.type = "A"
        resp = client.list_record_sets_by_zone(list_req)
        
        if resp.recordsets:
            for r in resp.recordsets:
                # [关键修复] 安全获取属性，防止 AttributeError
                r_line = getattr(r, 'line', None)
                
                # 精确匹配: 名字相同 且 线路为默认(None 或 'default_view')
                if r.name == target_name and r.type == "A" and (r_line == 'default_view' or r_line is None):
                    existing_record = r
                    break
    except Exception as e:
        print(f"Check Error: {e}")
        sys.exit(1)

    # 2. 辅助函数
    def do_update(rec_id, ttl_val):
        print(f"Updating Record {rec_id} -> IPs: {target_ips} (TTL: {ttl_val})")
        body = UpdateRecordSetReq(
            name=target_name,
            type="A",
            ttl=ttl_val,
            records=target_ips
        )
        req = UpdateRecordSetRequest(zone_id=zone_id, recordset_id=rec_id, body=body)
        client.update_record_set(req)
        print("Update Success!")

    def do_create(ttl_val):
        print(f"Creating Record -> IPs: {target_ips} (TTL: {ttl_val})")
        body = CreateRecordSetRequestBody(
            name=target_name,
            type="A",
            ttl=ttl_val,
            records=target_ips
        )
        req = CreateRecordSetRequest(zone_id=zone_id, body=body)
        resp = client.create_record_set(req)
        print(f"Create Success! ID: {resp.id}")

    # 3. 执行逻辑
    try:
        if existing_record:
            current_ips = sorted(existing_record.records)
            current_ttl = existing_record.ttl
            
            if current_ips == target_ips and current_ttl == target_ttl:
                print("Skipping: IPs and TTL match perfectly.")
                return

            try:
                do_update(existing_record.id, target_ttl)
            except exceptions.ClientRequestException as e:
                # TTL 降级重试
                if "TTL" in str(e) and target_ttl < 60:
                    print("Warning: TTL=1 rejected, retrying with TTL=60...")
                    do_update(existing_record.id, 60)
                else:
                    raise e
        else:
            try:
                do_create(target_ttl)
            except exceptions.ClientRequestException as e:
                if "TTL" in str(e) and target_ttl < 60:
                    print("Warning: TTL=1 rejected, retrying with TTL=60...")
                    do_create(60)
                else:
                    raise e

    except Exception as e:
        print(f"API Operation Failed: {e}")

if __name__ == "__main__":
    main()
