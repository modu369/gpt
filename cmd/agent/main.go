package main

import (
	"bufio"
	"context"
	"crypto/tls"
	"encoding/json"
	"fmt"
	"io"
	"log"
	"math/rand"
	"net"
	"net/http"
	"net/http/httputil"
	"net/url"
	"os"
	"os/exec"
	"path/filepath"
	"strconv"
	"strings"
	"sync"
	"sync/atomic"
	"time"

	"cfrelay/internal/common"
)

type runtime struct {
	mu               sync.RWMutex
	cfg              common.AgentConfig
	backends         []common.CFBackend
	rr               uint64
	seenDomains      map[string]bool
	certStatus       map[string]string
	rxBytes          uint64
	txBytes          uint64
	maxBandwidth     float64
	currentBandwidth float64
	lastSpeedtest    float64
	speedtestAt      time.Time
	lastNetSample    time.Time
}

func main() {
	master := env("AGENT_MASTER", "http://127.0.0.1:8080/tianyun123")
	nodeID := env("AGENT_NODE_ID", fmt.Sprintf("node-%d", rand.Intn(99999)))
	token := env("AGENT_TOKEN", "")
	enrollKey := env("AGENT_ENROLL_KEY", "")
	iface := env("AGENT_NET_IFACE", defaultIface())
	acmeWebroot := env("AGENT_ACME_WEBROOT", "/var/lib/cfrelay/acme")
	_ = os.MkdirAll(acmeWebroot, 0o755)

	if token == "" && enrollKey != "" {
		tk, err := enroll(master, enrollKey, nodeID)
		if err != nil {
			log.Fatal(err)
		}
		token = tk
	}
	if token == "" {
		log.Fatal("AGENT_TOKEN or AGENT_ENROLL_KEY is required")
	}

	rt := &runtime{seenDomains: map[string]bool{}, certStatus: map[string]string{}, maxBandwidth: float64(max(0, mustInt(env("AGENT_MAX_BW", "0"))))}
	if rt.maxBandwidth == 0 {
		rt.maxBandwidth = float64(detectMaxMbps(iface))
	}
	rt.lastSpeedtest = runSpeedtestMbps()
	rt.speedtestAt = time.Now()
	if rt.lastSpeedtest > 0 && (rt.maxBandwidth == 0 || rt.lastSpeedtest < rt.maxBandwidth) {
		rt.maxBandwidth = rt.lastSpeedtest
	}

	ctx := context.Background()
	go rt.syncLoop(ctx, master, token, nodeID)
	go rt.healthLoop()
	go rt.metricLoop(iface)
	go rt.heartbeatLoop(master, token, nodeID)
	go rt.renewLoop()

	h := http.HandlerFunc(func(w http.ResponseWriter, r *http.Request) {
		host := hostOnly(r.Host)
		if strings.HasPrefix(r.URL.Path, "/.well-known/acme-challenge/") {
			http.FileServer(http.Dir(acmeWebroot)).ServeHTTP(w, r)
			return
		}
		if !rt.allowed(host) {
			http.Error(w, "host blocked", http.StatusForbidden)
			return
		}
		rt.ensureCert(host, acmeWebroot, nodeID, master, token)
		if rt.certPending(host) {
			http.Error(w, "certificate pending", http.StatusTooEarly)
			return
		}
		if rt.pausedByPolicy() {
			http.Error(w, "node paused by policy", http.StatusServiceUnavailable)
			return
		}
		backend := rt.pickBackend()
		if backend == "" {
			http.Error(w, "no backend", http.StatusServiceUnavailable)
			return
		}
		target := &url.URL{Scheme: "https", Host: backend}
		proxy := httputil.NewSingleHostReverseProxy(target)
		proxy.Transport = &http.Transport{Proxy: http.ProxyFromEnvironment, MaxIdleConns: 4096, MaxIdleConnsPerHost: 1024, IdleConnTimeout: 120 * time.Second, TLSHandshakeTimeout: 5 * time.Second, ForceAttemptHTTP2: true, TLSClientConfig: &tls.Config{ServerName: host}}
		proxy.ErrorHandler = func(rw http.ResponseWriter, _ *http.Request, err error) {
			http.Error(rw, err.Error(), http.StatusBadGateway)
		}
		origIP, _, _ := net.SplitHostPort(r.RemoteAddr)
		if origIP != "" {
			r.Header.Set("X-Forwarded-For", appendXFF(r.Header.Get("X-Forwarded-For"), origIP))
			r.Header.Set("X-Real-IP", origIP)
			r.Header.Set("X-Relay-Real-IP", origIP)
		}
		r.Header.Set("X-Forwarded-Proto", proto(r))
		r.Host = host
		proxy.ServeHTTP(w, r)
	})

	go func() { log.Fatal(http.ListenAndServe(":80", h)) }()
	if certFile, keyFile := env("AGENT_TLS_CERT", ""), env("AGENT_TLS_KEY", ""); certFile != "" && keyFile != "" {
		log.Fatal((&http.Server{Addr: ":443", Handler: h}).ListenAndServeTLS(certFile, keyFile))
	}
	select {}
}

func (rt *runtime) syncLoop(ctx context.Context, master, token, nodeID string) {
	cli := &http.Client{Timeout: 5 * time.Second}
	for {
		select {
		case <-ctx.Done():
			return
		default:
		}
		req, _ := http.NewRequest(http.MethodGet, master+"/api/node/config?node_id="+url.QueryEscape(nodeID), nil)
		req.Header.Set("Authorization", "Bearer "+token)
		if resp, err := cli.Do(req); err == nil {
			if resp.StatusCode == 200 {
				var cfg common.AgentConfig
				_ = json.NewDecoder(resp.Body).Decode(&cfg)
				rt.mu.Lock()
				rt.cfg = cfg
				rt.backends = cfg.CFBackends
				syncChallengeFiles(env("AGENT_ACME_WEBROOT", "/var/lib/cfrelay/acme"), cfg.ACMEChallengeFiles)
				if cfg.MaxMbps > 0 {
					rt.maxBandwidth = float64(cfg.MaxMbps)
				}
				if cfg.RequestSpeedtest {
					rt.lastSpeedtest = runSpeedtestMbps()
					rt.speedtestAt = time.Now()
					rt.cfg.RequestSpeedtest = false
				}
				for _, d := range cfg.CertRetryDomains {
					rt.certStatus[d] = "pending"
					rt.seenDomains[d] = false
				}
				rt.cfg.CertRetryDomains = nil
				rt.mu.Unlock()
			}
			resp.Body.Close()
		}
		time.Sleep(3 * time.Second)
	}
}

func (rt *runtime) healthLoop() {
	for range time.Tick(4 * time.Second) {
		rt.mu.Lock()
		for i := range rt.backends {
			start := time.Now()
			c, err := net.DialTimeout("tcp", net.JoinHostPort(rt.backends[i].Address, "443"), 1200*time.Millisecond)
			rt.backends[i].Alive, rt.backends[i].RTTMs = err == nil, time.Since(start).Milliseconds()
			if c != nil {
				_ = c.Close()
			}
		}
		rt.mu.Unlock()
	}
}

func (rt *runtime) metricLoop(iface string) {
	for range time.Tick(2 * time.Second) {
		rx, tx := readNetDev(iface)
		rt.mu.Lock()
		if rt.lastNetSample.IsZero() {
			rt.rxBytes, rt.txBytes = rx, tx
			rt.lastNetSample = time.Now()
			rt.mu.Unlock()
			continue
		}
		d := time.Since(rt.lastNetSample).Seconds()
		rxDelta := float64(rx-rt.rxBytes) * 8 / 1_000_000 / d
		txDelta := float64(tx-rt.txBytes) * 8 / 1_000_000 / d
		rt.currentBandwidth = rxDelta + txDelta
		rt.rxBytes, rt.txBytes = rx, tx
		rt.lastNetSample = time.Now()
		rt.cfg.Paused = rt.evaluateLimit(rx, tx)
		rt.mu.Unlock()
	}
}

func (rt *runtime) evaluateLimit(rx, tx uint64) bool {
	if !rt.cfg.TrafficLimitEnabled || rt.cfg.TrafficLimitGB <= 0 || !rt.cfg.PauseWhenLimitExceeded {
		return false
	}
	limit := float64(rt.cfg.TrafficLimitGB) * 1024 * 1024 * 1024
	used := float64(rx + tx)
	if rt.cfg.TrafficMode == common.TrafficModeRx {
		used = float64(rx)
	}
	if rt.cfg.TrafficMode == common.TrafficModeTx {
		used = float64(tx)
	}
	if used >= limit {
		return true
	}
	remainPct := (limit - used) * 100 / limit
	if rt.cfg.TrafficWarnPercent <= 0 {
		return false
	}
	return remainPct < float64(rt.cfg.TrafficWarnPercent)
}

func (rt *runtime) pausedByPolicy() bool { rt.mu.RLock(); defer rt.mu.RUnlock(); return rt.cfg.Paused }
func (rt *runtime) pickBackend() string {
	rt.mu.RLock()
	defer rt.mu.RUnlock()
	alive := []string{}
	for _, b := range rt.backends {
		if b.Alive {
			alive = append(alive, b.Address)
		}
	}
	if len(alive) == 0 {
		return ""
	}
	i := atomic.AddUint64(&rt.rr, 1)
	return alive[i%uint64(len(alive))]
}
func (rt *runtime) allowed(host string) bool {
	rt.mu.RLock()
	defer rt.mu.RUnlock()
	if len(rt.cfg.Whitelist) == 0 {
		return rt.cfg.AutoCertWhenEmpty
	}
	for _, h := range rt.cfg.Whitelist {
		if strings.EqualFold(host, h) {
			return true
		}
	}
	return false
}

func (rt *runtime) heartbeatLoop(master, token, nodeID string) {
	for range time.Tick(10 * time.Second) {
		cpu, mem := cpuPercent(), memPercent()
		rt.mu.RLock()
		rx, tx := rt.rxBytes, rt.txBytes
		bw := rt.currentBandwidth
		cfg := rt.cfg
		maxBW := rt.maxBandwidth
		sptest := rt.lastSpeedtest
		sptestAt := rt.speedtestAt
		rt.mu.RUnlock()
		used := float64(rx+tx) / (1024 * 1024 * 1024)
		remain := float64(cfg.TrafficLimitGB) - used
		hb := common.NodeHeartbeat{NodeID: nodeID, CPUPercent: cpu, MemPercent: mem, BandwidthMbps: bw, MaxBandwidth: maxBW, RxBytes: rx, TxBytes: tx, TrafficUsedGB: used, TrafficRemain: remain, LastSpeedtestMbps: sptest, SpeedtestUpdatedAt: sptestAt}
		b, _ := json.Marshal(hb)
		req, _ := http.NewRequest(http.MethodPost, master+"/api/node/heartbeat", strings.NewReader(string(b)))
		req.Header.Set("Authorization", "Bearer "+token)
		req.Header.Set("Content-Type", "application/json")
		if resp, err := (&http.Client{Timeout: 5 * time.Second}).Do(req); err == nil {
			_ = resp.Body.Close()
		}
	}
}

func (rt *runtime) certPending(host string) bool {
	rt.mu.RLock()
	defer rt.mu.RUnlock()
	v := rt.certStatus[host]
	return v == "pending"
}

func (rt *runtime) ensureCert(host, webroot, nodeID, master, token string) {
	rt.mu.Lock()
	if rt.seenDomains[host] {
		rt.mu.Unlock()
		return
	}
	rt.seenDomains[host] = true
	rt.certStatus[host] = "pending"
	rt.mu.Unlock()
	go func() {
		status := "issued"
		errText := ""
		if env("AGENT_CERTBOT", "0") == "1" {
			cmd := exec.Command("certbot", "certonly", "--webroot", "-w", webroot, "-d", host, "--non-interactive", "--agree-tos", "-m", env("AGENT_CERT_EMAIL", "admin@example.com"))
			if out, err := cmd.CombinedOutput(); err != nil {
				status = "failed"
				errText = string(out)
			}
		} else {
			status = "disabled"
		}
		rt.mu.Lock()
		rt.certStatus[host] = status
		rt.mu.Unlock()
		task := common.CertTask{Domain: host, Method: "http", ManagedBy: nodeID, UpdatedAt: time.Now(), Status: status, LastError: errText}
		b, _ := json.Marshal(task)
		req, _ := http.NewRequest(http.MethodPost, master+"/api/node/cert", strings.NewReader(string(b)))
		req.Header.Set("Authorization", "Bearer "+token)
		req.Header.Set("Content-Type", "application/json")
		if resp, e := (&http.Client{Timeout: 5 * time.Second}).Do(req); e == nil {
			_ = resp.Body.Close()
		}
	}()
}

func (rt *runtime) renewLoop() {
	for range time.Tick(12 * time.Hour) {
		if env("AGENT_CERTBOT", "0") != "1" {
			continue
		}
		_ = exec.Command("certbot", "renew", "--non-interactive").Run()
	}
}

func syncChallengeFiles(webroot string, files []common.ACMEChallengeFile) {
	base := filepath.Join(webroot, ".well-known", "acme-challenge")
	_ = os.MkdirAll(base, 0o755)
	for _, f := range files {
		if f.Token == "" {
			continue
		}
		_ = os.WriteFile(filepath.Join(base, f.Token), []byte(f.Content), 0o644)
	}
}

func enroll(master, key, nodeID string) (string, error) {
	body, _ := json.Marshal(map[string]string{"node_id": nodeID})
	req, _ := http.NewRequest(http.MethodPost, master+"/api/enroll", strings.NewReader(string(body)))
	req.Header.Set("X-Enroll-Key", key)
	req.Header.Set("Content-Type", "application/json")
	resp, err := (&http.Client{Timeout: 5 * time.Second}).Do(req)
	if err != nil {
		return "", err
	}
	defer resp.Body.Close()
	if resp.StatusCode != 200 {
		b, _ := io.ReadAll(resp.Body)
		return "", fmt.Errorf("enroll failed: %s", string(b))
	}
	var out map[string]string
	_ = json.NewDecoder(resp.Body).Decode(&out)
	return out["token"], nil
}

func readNetDev(iface string) (uint64, uint64) {
	b, err := os.ReadFile("/proc/net/dev")
	if err != nil {
		return 0, 0
	}
	for _, l := range strings.Split(string(b), "\n") {
		l = strings.TrimSpace(l)
		if !strings.HasPrefix(l, iface+":") {
			continue
		}
		f := strings.Fields(strings.ReplaceAll(l, ":", " "))
		if len(f) < 11 {
			return 0, 0
		}
		rx, _ := strconv.ParseUint(f[1], 10, 64)
		tx, _ := strconv.ParseUint(f[9], 10, 64)
		return rx, tx
	}
	return 0, 0
}

var lastIdle, lastTotal uint64

func cpuPercent() float64 {
	f, err := os.Open("/proc/stat")
	if err != nil {
		return 0
	}
	defer f.Close()
	s := bufio.NewScanner(f)
	if !s.Scan() {
		return 0
	}
	fs := strings.Fields(s.Text())
	if len(fs) < 5 {
		return 0
	}
	vals := make([]uint64, 0, len(fs)-1)
	for _, v := range fs[1:] {
		n, _ := strconv.ParseUint(v, 10, 64)
		vals = append(vals, n)
	}
	total := uint64(0)
	for _, n := range vals {
		total += n
	}
	idle := vals[3]
	dt, di := total-lastTotal, idle-lastIdle
	lastTotal, lastIdle = total, idle
	if dt == 0 {
		return 0
	}
	return float64(dt-di) * 100 / float64(dt)
}
func memPercent() float64 {
	b, err := os.ReadFile("/proc/meminfo")
	if err != nil {
		return 0
	}
	m := map[string]float64{}
	for _, l := range strings.Split(string(b), "\n") {
		f := strings.Fields(l)
		if len(f) < 2 {
			continue
		}
		v, _ := strconv.ParseFloat(f[1], 64)
		m[strings.TrimSuffix(f[0], ":")] = v
	}
	t, a := m["MemTotal"], m["MemAvailable"]
	if t == 0 {
		return 0
	}
	return (t - a) * 100 / t
}

func runSpeedtestMbps() float64 {
	cmd := exec.Command("speedtest-cli", "--simple")
	out, err := cmd.Output()
	if err != nil {
		return 0
	}
	for _, line := range strings.Split(string(out), "\n") {
		if strings.HasPrefix(line, "Download:") {
			parts := strings.Fields(line)
			if len(parts) >= 2 {
				v, _ := strconv.ParseFloat(parts[1], 64)
				return v
			}
		}
	}
	return 0
}

func detectMaxMbps(iface string) int {
	if out, err := exec.Command("ethtool", iface).Output(); err == nil {
		for _, line := range strings.Split(string(out), "\n") {
			line = strings.TrimSpace(line)
			if strings.HasPrefix(line, "Speed:") {
				v := strings.TrimSpace(strings.TrimPrefix(line, "Speed:"))
				v = strings.TrimSuffix(strings.TrimSuffix(v, "Mb/s"), "Mbps")
				n, _ := strconv.Atoi(strings.TrimSpace(v))
				if n > 0 {
					return n
				}
			}
		}
	}
	return mustInt(env("AGENT_MAX_BW_FALLBACK", "1000"))
}

func defaultIface() string { return env("AGENT_NET_IFACE_FALLBACK", "eth0") }
func hostOnly(h string) string {
	if i := strings.Index(h, ":"); i > 0 {
		return h[:i]
	}
	return h
}
func appendXFF(prev, ip string) string {
	if prev == "" {
		return ip
	}
	return prev + ", " + ip
}
func proto(r *http.Request) string {
	if r.TLS != nil {
		return "https"
	}
	return "http"
}
func env(k, d string) string {
	if v := os.Getenv(k); v != "" {
		return v
	}
	return d
}
func max(a, b int) int {
	if a > b {
		return a
	}
	return b
}
func mustInt(v string) int { n, _ := strconv.Atoi(v); return n }
