# -*- coding: utf-8 -*-
# hw_txt_pusher.py V2.1 - Debug & Robust Search
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
    # 华为云 DNS 是全局服务，但在 SDK 中需要指定 Region 初始化端点
    # 如果用户填写的 Region 不存在 DNS 端点，可能会报错，建议默认 ap-southeast-1 或 cn-north-4
    if not region_id: region_id = 'ap-southeast-1'
    
    credentials = BasicCredentials(ak, sk)
    return DnsClient.new_builder() \
        .with_credentials(credentials) \
        .with_region(DnsRegion.value_of(region_id)) \
        .build()

def auto_find_zone_id(client, full_domain):
    """
    自动查找 ZoneID，增加详细调试信息
    """
    # 移除末尾的点，作为基础名称
    base_domain = full_domain.rstrip('.')
    parts = base_domain.split('.')
    
    print(f"DEBUG: Starting Zone discovery for: {base_domain}")

    # 逐级向上查找
    # 例如: a.b.c.com -> 查 b.c.com -> 查 c.com
    for i in range(len(parts) - 1):
        # 构建两种尝试名称：带点 和 不带点
        search_candidates = [
            ".".join(parts[i:]) + '.',  # kalaimg.top.
            ".".join(parts[i:])         # kalaimg.top
        ]
        
        for search_name in search_candidates:
            print(f"  > API Searching Zone: '{search_name}' ...")
            try:
                req = ListPublicZonesRequest()
                req.name = search_name
                # req.search_mode = "exact" # [修改] 移除精确匹配，增加容错
                
                resp = client.list_public_zones(req)
                
                if resp.zones:
                    for z in resp.zones:
                        print(f"    - Found candidate: {z.name} (ID: {z.id})")
                        # 再次在客户端确认匹配（忽略末尾的点进行对比）
                        if z.name.rstrip('.') == search_name.rstrip('.'):
                            print(f"✅ MATCHED Zone: {z.name} (ID: {z.id})")
                            return z.id
                else:
                    print("    - No results.")
                    
            except exceptions.ClientRequestException as e:
                print(f"    ! API Error: {e.error_code} - {e.error_msg}")
            except Exception as e:
                print(f"    ! System Error: {e}")
            
    return None

def main():
    print("--- TXT Pusher V2.1 (Verbose Debug) ---")
    
    if len(sys.argv) < 2: 
        print("Error: Missing config file")
        sys.exit(1)
    
    ak = os.environ.get('CLOUD_SDK_AK')
    sk = os.environ.get('CLOUD_SDK_SK')
    if not ak or not sk:
        print("Error: AK/SK env vars missing")
        sys.exit(1)

    try:
        with open(sys.argv[1], 'r', encoding='utf-8') as f:
            data = json.load(f)
    except Exception as e:
        print(f"Error loading JSON: {e}")
        sys.exit(1)

    region_id = data.get('region')
    target_name = data.get('domain')    
    txt_value = data.get('value')
    manual_zone_id = data.get('zone_id') # 后台配置的 ZoneID (如果有)

    if not target_name or not txt_value:
        print("Error: Invalid config params")
        sys.exit(1)

    # 规范化目标 TXT 记录名
    if not target_name.endswith('.'): target_name += '.'
    final_records = [f"\"{txt_value}\""]

    # 初始化
    print(f"DEBUG: Init Client with Region: {region_id}")
    try:
        client = get_client(ak, sk, region_id)
    except Exception as e:
        print(f"Error initializing client: {e}")
        sys.exit(1)

    # --- 1. 确定 Zone ID ---
    final_zone_id = manual_zone_id
    
    if not final_zone_id:
        print("No ZoneID provided, attempting auto-discovery...")
        clean_domain = target_name
        if "_acme-challenge." in target_name:
            clean_domain = target_name.replace("_acme-challenge.", "")
            
        final_zone_id = auto_find_zone_id(client, clean_domain)
    
    if not final_zone_id:
        print("\n❌ CRITICAL: Could not find ZoneID.")
        print("Possible reasons:")
        print("1. Region is wrong (try changing 'hw_region' in admin panel).")
        print("2. Domain is not hosted in this Huawei Cloud account.")
        print("3. API permissions are missing (DNS Administrator).")
        sys.exit(1)

    # --- 2. 操作 TXT 记录 ---
    existing_record = None
    try:
        print(f"Checking existing TXT records in Zone {final_zone_id}...")
        list_req = ListRecordSetsByZoneRequest()
        list_req.zone_id = final_zone_id
        list_req.name = target_name
        list_req.type = "TXT"
        resp = client.list_record_sets_by_zone(list_req)
        
        if resp.recordsets:
            for r in resp.recordsets:
                if r.name == target_name and r.type == "TXT":
                    existing_record = r
                    break
    except Exception as e:
        print(f"Check Error: {e}")
        sys.exit(1)

    try:
        if existing_record:
            print(f"Updating TXT Record {existing_record.id} -> {txt_value}")
            body = UpdateRecordSetReq(
                name=target_name,
                type="TXT",
                ttl=60,
                records=final_records
            )
            req = UpdateRecordSetRequest(zone_id=final_zone_id, recordset_id=existing_record.id, body=body)
            client.update_record_set(req)
            print("Update Success!")
        else:
            print(f"Creating TXT Record -> {txt_value}")
            body = CreateRecordSetRequestBody(
                name=target_name,
                type="TXT",
                ttl=60,
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
