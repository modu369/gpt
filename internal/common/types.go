package common

import "time"

type CFBackend struct {
	Address string `json:"address"`
	Alive   bool   `json:"alive"`
}

type AgentConfig struct {
	NodeID             string      `json:"node_id"`
	Whitelist          []string    `json:"whitelist"`
	CFBackends         []CFBackend `json:"cf_backends"`
	AutoCertWhenEmpty  bool        `json:"auto_cert_when_empty"`
	AuthBearer         string      `json:"auth_bearer"`
	MaxMbps            int         `json:"max_mbps"`
	TrafficLimitGB     int         `json:"traffic_limit_gb"`
	TrafficWarnPercent int         `json:"traffic_warn_percent"`
	UpdatedAt          time.Time   `json:"updated_at"`
}

type NodeHeartbeat struct {
	NodeID        string    `json:"node_id"`
	CPUPercent    float64   `json:"cpu_percent"`
	MemPercent    float64   `json:"mem_percent"`
	BandwidthMbps float64   `json:"bandwidth_mbps"`
	RxBytes       uint64    `json:"rx_bytes"`
	TxBytes       uint64    `json:"tx_bytes"`
	Timestamp     time.Time `json:"timestamp"`
}
