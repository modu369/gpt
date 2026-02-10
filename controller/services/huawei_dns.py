"""Huawei Cloud DNS integration placeholder.

Implement IAM auth and recordset upsert/delete with AK/SK signing here.
"""

from dataclasses import dataclass
from typing import List


@dataclass
class NodeWeight:
    ip: str
    weight: int


def update_weighted_records(cname: str, targets: List[NodeWeight]) -> None:
    # TODO: integrate with HuaweiCloud DNS API for intl site.
    # Keep this function idempotent and callable from scheduler.
    _ = cname, targets
