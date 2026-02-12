package config

import (
	"bytes"
	"context"
	"encoding/json"
	"fmt"
	"io"
	"net/http"
	"runtime"
	"strings"
	"sync"
	"time"
)

type CertPair struct {
	Domain   string `json:"domain"`
	CertBody string `json:"cert_body"`
	KeyBody  string `json:"key_body"`
}

type RemoteConfig struct {
	Whitelist []string   `json:"whitelist"`
	CFIPs     []string   `json:"cf_ips"`
	HTTPPort  int        `json:"http_port"`
	HTTPSPort int        `json:"https_port"`
	Timestamp int64      `json:"timestamp"`
	Certs     []CertPair `json:"certs"`
}

type RuntimeSnapshot struct {
	Whitelist map[string]struct{}
	CFIPs     []string
	HTTPPort  int
	HTTPSPort int
	Version   int64
}

type HeartbeatPayload struct {
	CPU         float64 `json:"cpu"`
	RAMMB       uint64  `json:"ram"`
	Goroutines  int     `json:"goroutines"`
	TrafficUp   uint64  `json:"traffic_up"`
	TrafficDown uint64  `json:"traffic_down"`
	MaxBW       int     `json:"max_bw"`
}

type APIClient struct {
	baseURL    string
	secret     string
	httpClient *http.Client
}

func NewAPIClient(baseURL, secret string) *APIClient {
	return &APIClient{
		baseURL: strings.TrimRight(baseURL, "/"),
		secret:  secret,
		httpClient: &http.Client{
			Timeout: 5 * time.Second,
		},
	}
}

func (c *APIClient) FetchConfig(ctx context.Context) (RemoteConfig, error) {
	req, err := http.NewRequestWithContext(ctx, http.MethodGet, c.baseURL+"/get_config.php", nil)
	if err != nil {
		return RemoteConfig{}, err
	}
	req.Header.Set("X-Node-Secret", c.secret)

	resp, err := c.httpClient.Do(req)
	if err != nil {
		return RemoteConfig{}, err
	}
	defer resp.Body.Close()

	if resp.StatusCode != http.StatusOK {
		body, _ := io.ReadAll(io.LimitReader(resp.Body, 2048))
		return RemoteConfig{}, fmt.Errorf("fetch config status=%d body=%s", resp.StatusCode, string(body))
	}

	var cfg RemoteConfig
	if err := json.NewDecoder(resp.Body).Decode(&cfg); err != nil {
		return RemoteConfig{}, err
	}
	if len(cfg.CFIPs) == 0 {
		cfg.CFIPs = []string{"1.1.1.1"}
	}
	if cfg.HTTPPort <= 0 {
		cfg.HTTPPort = 80
	}
	if cfg.HTTPSPort <= 0 {
		cfg.HTTPSPort = 443
	}
	return cfg, nil
}

func (c *APIClient) SendHeartbeat(ctx context.Context, hb HeartbeatPayload) error {
	if hb.RAMMB == 0 {
		var mem runtime.MemStats
		runtime.ReadMemStats(&mem)
		hb.RAMMB = mem.Alloc / 1024 / 1024
	}
	if hb.Goroutines == 0 {
		hb.Goroutines = runtime.NumGoroutine()
	}

	buf, err := json.Marshal(hb)
	if err != nil {
		return err
	}

	req, err := http.NewRequestWithContext(ctx, http.MethodPost, c.baseURL+"/heartbeat.php", bytes.NewReader(buf))
	if err != nil {
		return err
	}
	req.Header.Set("X-Node-Secret", c.secret)
	req.Header.Set("Content-Type", "application/json")

	resp, err := c.httpClient.Do(req)
	if err != nil {
		return err
	}
	defer resp.Body.Close()

	if resp.StatusCode != http.StatusOK {
		body, _ := io.ReadAll(io.LimitReader(resp.Body, 2048))
		return fmt.Errorf("heartbeat status=%d body=%s", resp.StatusCode, string(body))
	}

	return nil
}

type Manager struct {
	mu       sync.RWMutex
	snapshot RuntimeSnapshot
}

func NewManager() *Manager {
	return &Manager{}
}

func (m *Manager) Apply(cfg RemoteConfig) (changed bool) {
	m.mu.Lock()
	defer m.mu.Unlock()

	if m.snapshot.Version != 0 && cfg.Timestamp <= m.snapshot.Version {
		return false
	}

	wl := make(map[string]struct{}, len(cfg.Whitelist))
	for _, h := range cfg.Whitelist {
		h = strings.ToLower(strings.TrimSpace(h))
		if h != "" {
			wl[h] = struct{}{}
		}
	}

	ips := make([]string, 0, len(cfg.CFIPs))
	for _, ip := range cfg.CFIPs {
		ip = strings.TrimSpace(ip)
		if ip != "" {
			ips = append(ips, ip)
		}
	}
	if len(ips) == 0 {
		ips = []string{"1.1.1.1"}
	}

	m.snapshot = RuntimeSnapshot{
		Whitelist: wl,
		CFIPs:     ips,
		HTTPPort:  cfg.HTTPPort,
		HTTPSPort: cfg.HTTPSPort,
		Version:   cfg.Timestamp,
	}
	return true
}

func (m *Manager) Snapshot() RuntimeSnapshot {
	m.mu.RLock()
	defer m.mu.RUnlock()

	wl := make(map[string]struct{}, len(m.snapshot.Whitelist))
	for k := range m.snapshot.Whitelist {
		wl[k] = struct{}{}
	}
	ips := append([]string(nil), m.snapshot.CFIPs...)
	return RuntimeSnapshot{
		Whitelist: wl,
		CFIPs:     ips,
		HTTPPort:  m.snapshot.HTTPPort,
		HTTPSPort: m.snapshot.HTTPSPort,
		Version:   m.snapshot.Version,
	}
}
