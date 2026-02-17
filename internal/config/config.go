package config

// Legacy file-based config loader kept for backward compatibility with
// earlier local-only testing flows. Phase-3 runtime no longer uses it.

import (
	"encoding/json"
	"fmt"
	"os"
)

type Config struct {
	Whitelist   []string `json:"whitelist"`
	CFIPs       []string `json:"cf_ips"`
	HTTPPort    int      `json:"http_port"`
	HTTPSPort   int      `json:"https_port"`
	TLSCertFile string   `json:"tls_cert_file"`
	TLSKeyFile  string   `json:"tls_key_file"`
}

func Load(path string) (Config, error) {
	data, err := os.ReadFile(path)
	if err != nil {
		return Config{}, fmt.Errorf("read config: %w", err)
	}

	var cfg Config
	if err := json.Unmarshal(data, &cfg); err != nil {
		return Config{}, fmt.Errorf("decode config: %w", err)
	}

	if len(cfg.Whitelist) == 0 {
		return Config{}, fmt.Errorf("whitelist is empty")
	}
	if cfg.HTTPPort <= 0 {
		cfg.HTTPPort = 80
	}
	if cfg.HTTPSPort <= 0 {
		cfg.HTTPSPort = 443
	}
	if len(cfg.CFIPs) == 0 {
		cfg.CFIPs = []string{"1.1.1.1"}
	}

	return cfg, nil
}
