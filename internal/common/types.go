package common

import "time"

type CFBackend struct {
	Address string `json:"address"`
	Alive   bool   `json:"alive"`
	RTTMs   int64  `json:"rtt_ms"`
}

type TrafficMode string

type CertMethod string

const (
	TrafficModeBoth TrafficMode = "both"
	TrafficModeRx   TrafficMode = "rx"
	TrafficModeTx   TrafficMode = "tx"

	CertMethodHTTP CertMethod = "http"
	CertMethodDNS  CertMethod = "dns"
)

type ACMEChallengeFile struct {
	Domain    string    `json:"domain"`
	Token     string    `json:"token"`
	Content   string    `json:"content"`
	UpdatedAt time.Time `json:"updated_at"`
}

type AgentConfig struct {
	NodeID                 string              `json:"node_id"`
	Whitelist              []string            `json:"whitelist"`
	CFBackends             []CFBackend         `json:"cf_backends"`
	AutoCertWhenEmpty      bool                `json:"auto_cert_when_empty"`
	MaxMbps                int                 `json:"max_mbps"`
	RequestSpeedtest       bool                `json:"request_speedtest"`
	CertRetryDomains       []string            `json:"cert_retry_domains"`
	ACMEChallengeFiles     []ACMEChallengeFile `json:"acme_challenge_files"`
	TrafficLimitEnabled    bool                `json:"traffic_limit_enabled"`
	TrafficLimitGB         int                 `json:"traffic_limit_gb"`
	TrafficWarnPercent     int                 `json:"traffic_warn_percent"`
	TrafficMode            TrafficMode         `json:"traffic_mode"`
	PauseWhenLimitExceeded bool                `json:"pause_when_limit_exceeded"`
	Paused                 bool                `json:"paused"`
	UpdatedAt              time.Time           `json:"updated_at"`
}

type CertTask struct {
	Domain        string              `json:"domain"`
	Method        CertMethod          `json:"method"`
	Status        string              `json:"status"`
	LastError     string              `json:"last_error,omitempty"`
	UpdatedAt     time.Time           `json:"updated_at"`
	ManagedBy     string              `json:"managed_by"`
	RetryCount    int                 `json:"retry_count"`
	DNSName       string              `json:"dns_name,omitempty"`
	DNSValue      string              `json:"dns_value,omitempty"`
	LocalVerified bool                `json:"local_verified"`
	Files         []ACMEChallengeFile `json:"files,omitempty"`
}

type NodeHeartbeat struct {
	NodeID             string    `json:"node_id"`
	CPUPercent         float64   `json:"cpu_percent"`
	MemPercent         float64   `json:"mem_percent"`
	BandwidthMbps      float64   `json:"bandwidth_mbps"`
	MaxBandwidth       float64   `json:"max_bandwidth_mbps"`
	RxBytes            uint64    `json:"rx_bytes"`
	TxBytes            uint64    `json:"tx_bytes"`
	TrafficUsedGB      float64   `json:"traffic_used_gb"`
	TrafficRemain      float64   `json:"traffic_remain_gb"`
	LastSpeedtestMbps  float64   `json:"last_speedtest_mbps"`
	SpeedtestUpdatedAt time.Time `json:"speedtest_updated_at"`
	Timestamp          time.Time `json:"timestamp"`
}

type HuaweiDNSConfig struct {
	Enabled        bool   `json:"enabled"`
	Endpoint       string `json:"endpoint"`
	ZoneID         string `json:"zone_id"`
	RecordsetID    string `json:"recordset_id"`
	AccessKey      string `json:"access_key"`
	SecretKey      string `json:"secret_key"`
	SchedulerCNAME string `json:"scheduler_cname"`
}
