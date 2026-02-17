# -*- coding: utf-8 -*-
# hw_txt_pusher.py V6.0 - Targeted Cleanup & Manual Protection
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
    UpdateRecordSetReq,
    ListPublicZonesRequest
)

def get_client(ak, sk, region_id):
    if not region_id: region_id = 'ap-southeast-1'
    credentials = BasicCredentials(ak, sk)
    return DnsClient.new_builder() \
        .with_credentials(credentials) \
        .with_region(DnsRegion.value_of(region_id)) \
        .build()

def auto_find_zone_id(client, full_domain):
    base_domain = full_domain.rstrip('.')
    parts = base_domain.split('.')
    print(f"DEBUG: Finding Zone for: {base_domain}")
    for i in range(len(parts) - 1):
        search_candidates = [".".join(parts[i:]) + '.', ".".join(parts[i:])]
        for search_name in search_candidates:
            try:
                req = ListPublicZonesRequest()
                req.name = search_name
                resp = client.list_public_zones(req)
                if resp.zones:
                    for z in resp.zones:
                        if z.name.rstrip('.') == search_name.rstrip('.'):
                            print(f"✅ Found Zone: {z.name} (ID: {z.id})")
                            return z.id
            except: pass
    return None

def main():
    print("--- TXT Pusher V6.0 (Targeted Cleanup) ---")
    if len(sys.argv) < 2: 
        print("Error: Missing config file")
        sys.exit(1)
    
    ak = os.environ.get('CLOUD_SDK_AK')
    sk = os.environ.get('CLOUD_SDK_SK')
    if not ak or not sk:
        print("Error: AK/SK missing")
        sys.exit(1)

    try:
        with open(sys.argv[1], 'r', encoding='utf-8') as f: data = json.load(f)
    except Exception as e:
        print(f"Error loading JSON: {e}")
        sys.exit(1)

    region_id = data.get('region')
    target_name = data.get('domain')    
    new_token = data.get('value')
    # [新增] 要删除的旧令牌 (数据库中记录的)
    old_token_to_remove = data.get('remove_value') 
    manual_zone_id = data.get('zone_id')

    if not target_name or not new_token:
        print("Error: Invalid config")
        sys.exit(1)

    if not target_name.endswith('.'): target_name += '.'
    
    # 华为云格式要求
    new_token_quoted = f'"{new_token}"'
    remove_token_quoted = f'"{old_token_to_remove}"' if old_token_to_remove else None

    client = get_client(ak, sk, region_id)

    # 1. 确定 Zone ID
    final_zone_id = manual_zone_id
    if not final_zone_id:
        clean_domain = target_name.replace("_acme-challenge.", "") if "_acme-challenge." in target_name else target_name
        final_zone_id = auto_find_zone_id(client, clean_domain)
    
    if not final_zone_id:
        print("❌ Error: ZoneID not found")
        sys.exit(1)

    # 2. 查找现有记录
    existing_record = None
    try:
        search_names = [target_name, target_name.rstrip('.')]
        for s_name in search_names:
            list_req = ListRecordSetsByZoneRequest()
            list_req.zone_id = final_zone_id
            list_req.name = s_name
            list_req.type = "TXT"
            resp = client.list_record_sets_by_zone(list_req)
            if resp.recordsets:
                for r in resp.recordsets:
                    if r.name.rstrip('.') == target_name.rstrip('.') and r.type == "TXT":
                        existing_record = r
                        break
            if existing_record: break
    except: pass

    # 3. 核心逻辑: 构建新列表
    final_records = []
    
    if existing_record:
        print(f"Analyzing {len(existing_record.records)} existing values...")
        for val in existing_record.records:
            # 1. 如果现有值 == 本次新值，跳过 (防止重复)
            if val == new_token_quoted:
                continue
            
            # 2. 如果现有值 == 数据库记录的旧值，删除 (精准命中)
            if remove_token_quoted and val == remove_token_quoted:
                print(f"  - Removing known old token: {val}")
                continue
            
            # 3. 其他所有值 (包括 Cloudflare)，全部保留
            print(f"  + Keeping record: {val}")
            final_records.append(val)

    # 4. 追加新值
    final_records.append(new_token_quoted)

    # 5. 提交
    try:
        if existing_record:
            print(f"Updating Record {existing_record.id} -> Count: {len(final_records)}")
            body = UpdateRecordSetReq(
                name=target_name,
                type="TXT",
                ttl=20,
                records=final_records
            )
            req = UpdateRecordSetRequest(zone_id=final_zone_id, recordset_id=existing_record.id, body=body)
            client.update_record_set(req)
            print("Update Success!")
        else:
            print(f"Creating New Record -> {new_token_quoted}")
            body = CreateRecordSetRequestBody(
                name=target_name,
                type="TXT",
                ttl=20,
                records=final_records
            )
            req = CreateRecordSetRequest(zone_id=final_zone_id, body=body)
            resp = client.create_record_set(req)
            print(f"Create Success! ID: {resp.id}")

    except exceptions.ClientRequestException as e:
        print(f"API Error: {e.error_code} - {e.error_msg}")
        sys.exit(1)

if __name__ == "__main__":
    main()
