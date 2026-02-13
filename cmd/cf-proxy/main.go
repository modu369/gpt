package main

import (
	"context"
	"crypto/tls"
	"encoding/json"
	"flag"
	"fmt"
	"log"
	"net"
	"net/http"
	"os"
	"path"
	"runtime"
	"strconv"
	"strings"
	"sync"
	"time"

	"github.com/example/cf-proxy/internal/config"
	"github.com/example/cf-proxy/internal/proxy"
)

type certStore struct {
	mu    sync.RWMutex
	certs map[string]*tls.Certificate
}

func newCertStore() *certStore {
	return &certStore{certs: make(map[string]*tls.Certificate)}
}

func (s *certStore) ReplaceFromPairs(pairs []config.CertPair) int {
	next := make(map[string]*tls.Certificate)
	for _, pair := range pairs {
		domain := strings.ToLower(strings.TrimSpace(pair.Domain))
		if domain == "" || pair.CertBody == "" || pair.KeyBody == "" {
			continue
		}
		cert, err := tls.X509KeyPair([]byte(pair.CertBody), []byte(pair.KeyBody))
		if err != nil {
			log.Printf("[TLS] parse cert failed domain=%s err=%v", domain, err)
			continue
		}
		next[domain] = &cert
	}

	s.mu.Lock()
	s.certs = next
	s.mu.Unlock()
	return len(next)
}

func (s *certStore) GetCertificate(hello *tls.ClientHelloInfo) (*tls.Certificate, error) {
	host := strings.ToLower(strings.TrimSpace(hello.ServerName))
	if host == "" {
		return nil, fmt.Errorf("missing SNI")
	}

	s.mu.RLock()
	defer s.mu.RUnlock()

	if cert, ok := s.certs[host]; ok {
		return cert, nil
	}

	for pattern, cert := range s.certs {
		if strings.HasPrefix(pattern, "*.") {
			if ok, _ := path.Match(pattern, host); ok {
				return cert, nil
			}
		}
	}

	return nil, fmt.Errorf("no certificate found for %s", host)
}

func refreshConfig(ctx context.Context, client *config.APIClient, manager *config.Manager, store *certStore) error {
	cfg, err := client.FetchConfig(ctx)
	if err != nil {
		return err
	}
	if manager.Apply(cfg) {
		s := manager.Snapshot()
		loaded := store.ReplaceFromPairs(cfg.Certs)
		log.Printf("[Config] updated: whitelist=%d cf_ips=%d certs=%d version=%d", len(s.Whitelist), len(s.CFIPs), loaded, s.Version)
	}
	return nil
}

func startAdminListener(client *config.APIClient, manager *config.Manager, store *certStore, nodeSecret, listenAddr string) {
	mux := http.NewServeMux()
	mux.HandleFunc("/reload", func(w http.ResponseWriter, r *http.Request) {
		if r.Method != http.MethodPost {
			http.Error(w, "Method Not Allowed", http.StatusMethodNotAllowed)
			return
		}
		if r.Header.Get("X-Node-Secret") != nodeSecret {
			http.Error(w, "Forbidden", http.StatusForbidden)
			return
		}

		ctx, cancel := context.WithTimeout(r.Context(), 6*time.Second)
		defer cancel()
		if err := refreshConfig(ctx, client, manager, store); err != nil {
			log.Printf("[Admin] reload failed: %v", err)
			http.Error(w, "Reload Failed", http.StatusInternalServerError)
			return
		}

		log.Printf("[Admin] force reload completed")
		w.WriteHeader(http.StatusOK)
		_, _ = w.Write([]byte("Reloaded Success"))
	})

	go func() {
		log.Printf("Admin command listener on %s", listenAddr)
		if err := http.ListenAndServe(listenAddr, mux); err != nil {
			log.Printf("admin listener stopped: %v", err)
		}
	}()
}

type HardwareConfig struct {
	MaxBW    int `json:"max_bw"`
	MaxRAM   int `json:"max_ram"`
	CPUCores int `json:"cpu_cores"`
}

func getSystemRAMUsage() uint64 {
	data, err := os.ReadFile("/proc/meminfo")
	if err != nil {
		return 0
	}
	lines := strings.Split(string(data), "\n")
	var total, avail uint64
	for _, line := range lines {
		parts := strings.Fields(line)
		if len(parts) < 2 {
			continue
		}
		val, _ := strconv.ParseUint(parts[1], 10, 64)
		if strings.HasPrefix(parts[0], "MemTotal") {
			total = val
		} else if strings.HasPrefix(parts[0], "MemAvailable") {
			avail = val
		}
	}
	if total < avail {
		return 0
	}
	return (total - avail) / 1024
}

func getSystemCPUUsage(prevIdle, prevTotal *uint64) float64 {
	data, err := os.ReadFile("/proc/stat")
	if err != nil {
		return 0
	}
	for _, line := range strings.Split(string(data), "\n") {
		if !strings.HasPrefix(line, "cpu ") {
			continue
		}
		fields := strings.Fields(line)
		if len(fields) < 5 {
			return 0
		}
		user, _ := strconv.ParseUint(fields[1], 10, 64)
		nice, _ := strconv.ParseUint(fields[2], 10, 64)
		sys, _ := strconv.ParseUint(fields[3], 10, 64)
		idle, _ := strconv.ParseUint(fields[4], 10, 64)
		total := user + nice + sys + idle

		diffTotal := total - *prevTotal
		diffIdle := idle - *prevIdle

		*prevTotal = total
		*prevIdle = idle

		if diffTotal == 0 {
			return 0
		}
		return (1.0 - float64(diffIdle)/float64(diffTotal)) * 100.0
	}
	return 0
}

func main() {
	masterURL := flag.String("master", "", "control-plane api base url, e.g. http://1.2.3.4/cf-master/api")
	nodeSecret := flag.String("secret", "", "node secret key")
	heartbeatInterval := flag.Duration("heartbeat-interval", 10*time.Second, "heartbeat and config refresh interval")
	adminListenAddr := flag.String("admin-listen", ":7777", "listen address for push-reload admin endpoint")
	flag.Parse()

	if *masterURL == "" || *nodeSecret == "" {
		log.Fatal("missing required flags: -master and -secret")
	}

	client := config.NewAPIClient(*masterURL, *nodeSecret)
	manager := config.NewManager()
	store := newCertStore()

	ctx, cancel := context.WithTimeout(context.Background(), 8*time.Second)
	if err := refreshConfig(ctx, client, manager, store); err != nil {
		cancel()
		log.Fatalf("initial config fetch failed: %v", err)
	}
	cancel()

	snap := manager.Snapshot()
	engine := proxy.New(manager)
	handler := engine.Handler()

	hwCfg := HardwareConfig{}
	if hwRaw, err := os.ReadFile("/etc/cf-proxy/hardware.json"); err == nil {
		_ = json.Unmarshal(hwRaw, &hwCfg)
	} else {
		if bwRaw, err := os.ReadFile("/etc/cf-proxy/bandwidth.conf"); err == nil {
			_, _ = fmt.Sscanf(string(bwRaw), "%d", &hwCfg.MaxBW)
		}
	}

	httpSrv := &http.Server{Addr: proxy.HTTPAddr(snap.HTTPPort), Handler: handler}
	httpsSrv := &http.Server{Addr: proxy.HTTPAddr(snap.HTTPSPort), Handler: handler, TLSConfig: &tls.Config{GetCertificate: store.GetCertificate, MinVersion: tls.VersionTLS12}}

	startAdminListener(client, manager, store, *nodeSecret, *adminListenAddr)

	var prevIdle, prevTotal uint64
	getSystemCPUUsage(&prevIdle, &prevTotal)

	go func() {
		ticker := time.NewTicker(*heartbeatInterval)
		defer ticker.Stop()
		for range ticker.C {
			up, down := engine.ConsumeTraffic()
			sysCPU := getSystemCPUUsage(&prevIdle, &prevTotal)
			sysRAM := getSystemRAMUsage()

			ctx, cancel := context.WithTimeout(context.Background(), 6*time.Second)
			if err := client.SendHeartbeat(ctx, config.HeartbeatPayload{
				CPU:         sysCPU,
				RAMMB:       sysRAM,
				Goroutines:  runtime.NumGoroutine(),
				TrafficUp:   up,
				TrafficDown: down,
				MaxBW:       hwCfg.MaxBW,
				MaxRAM:      hwCfg.MaxRAM,
				CPUCores:    hwCfg.CPUCores,
			}); err != nil {
				log.Printf("[Heartbeat] failed: %v", err)
			}
			if err := refreshConfig(ctx, client, manager, store); err != nil {
				log.Printf("[Config] refresh failed: %v", err)
			}
			cancel()
		}
	}()

	go func() {
		log.Printf("HTTP proxy listening on %s", httpSrv.Addr)
		if err := httpSrv.ListenAndServe(); err != nil && err != http.ErrServerClosed {
			log.Fatalf("http server failed: %v", err)
		}
	}()

	log.Printf("HTTPS proxy listening on %s", httpsSrv.Addr)
	ln, err := net.Listen("tcp", httpsSrv.Addr)
	if err != nil {
		log.Fatalf("https listen failed: %v", err)
	}
	tlsListener := tls.NewListener(ln, httpsSrv.TLSConfig)
	if err := httpsSrv.Serve(tlsListener); err != nil && err != http.ErrServerClosed {
		log.Fatalf("https serve failed: %v", err)
	}
}
