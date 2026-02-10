package main

import (
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
	"strings"
	"sync"
	"sync/atomic"
	"time"

	"cfrelay/internal/common"
)

type runtime struct {
	mu       sync.RWMutex
	cfg      common.AgentConfig
	backends []common.CFBackend
	rr       uint64
}

func main() {
	master := env("AGENT_MASTER", "http://127.0.0.1:8080/tianyun123")
	nodeID := env("AGENT_NODE_ID", fmt.Sprintf("node-%d", rand.Intn(99999)))
	token := env("AGENT_TOKEN", "")
	enrollKey := env("AGENT_ENROLL_KEY", "")

	if token == "" && enrollKey != "" {
		tk, err := enroll(master, enrollKey, nodeID)
		if err != nil {
			log.Fatal(err)
		}
		token = tk
		log.Printf("enrolled node=%s", nodeID)
	}
	if token == "" {
		log.Fatal("AGENT_TOKEN or AGENT_ENROLL_KEY is required")
	}

	rt := &runtime{}
	ctx := context.Background()
	go rt.syncLoop(ctx, master, token, nodeID)
	go rt.healthLoop()
	go heartbeatLoop(master, token, nodeID)

	h := http.HandlerFunc(func(w http.ResponseWriter, r *http.Request) {
		host := hostOnly(r.Host)
		if !rt.allowed(host) {
			http.Error(w, "host blocked", http.StatusForbidden)
			return
		}
		backend := rt.pickBackend()
		if backend == "" {
			http.Error(w, "no backend", http.StatusServiceUnavailable)
			return
		}
		target := &url.URL{Scheme: "https", Host: backend}
		proxy := httputil.NewSingleHostReverseProxy(target)
		proxy.Transport = &http.Transport{
			Proxy:               http.ProxyFromEnvironment,
			MaxIdleConns:        4096,
			MaxIdleConnsPerHost: 1024,
			IdleConnTimeout:     120 * time.Second,
			TLSHandshakeTimeout: 5 * time.Second,
			ForceAttemptHTTP2:   true,
			TLSClientConfig:     &tls.Config{ServerName: host},
		}
		proxy.ErrorHandler = func(rw http.ResponseWriter, req *http.Request, err error) {
			http.Error(rw, err.Error(), http.StatusBadGateway)
		}
		origIP, _, _ := net.SplitHostPort(r.RemoteAddr)
		if origIP != "" {
			r.Header.Set("X-Forwarded-For", appendXFF(r.Header.Get("X-Forwarded-For"), origIP))
			r.Header.Set("X-Real-IP", origIP)
			r.Header.Set("X-Relay-Real-IP", origIP)
		}
		r.Header.Set("X-Forwarded-Proto", "https")
		r.Host = host
		proxy.ServeHTTP(w, r)
	})

	go func() {
		log.Fatal(http.ListenAndServe(":80", h))
	}()

	certFile, keyFile := env("AGENT_TLS_CERT", ""), env("AGENT_TLS_KEY", "")
	if certFile != "" && keyFile != "" {
		srv := &http.Server{Addr: ":443", Handler: h}
		log.Fatal(srv.ListenAndServeTLS(certFile, keyFile))
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
		resp, err := cli.Do(req)
		if err == nil && resp.StatusCode == 200 {
			var cfg common.AgentConfig
			_ = json.NewDecoder(resp.Body).Decode(&cfg)
			resp.Body.Close()
			rt.mu.Lock()
			rt.cfg = cfg
			rt.backends = cfg.CFBackends
			rt.mu.Unlock()
		}
		time.Sleep(3 * time.Second)
	}
}

func (rt *runtime) healthLoop() {
	for {
		rt.mu.Lock()
		for i := range rt.backends {
			addr := rt.backends[i].Address
			c, err := net.DialTimeout("tcp", net.JoinHostPort(addr, "443"), 1200*time.Millisecond)
			rt.backends[i].Alive = err == nil
			if c != nil {
				c.Close()
			}
		}
		rt.mu.Unlock()
		time.Sleep(4 * time.Second)
	}
}

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

func heartbeatLoop(master, token, nodeID string) {
	for {
		hb := common.NodeHeartbeat{NodeID: nodeID, CPUPercent: 0, MemPercent: 0, BandwidthMbps: 0}
		b, _ := json.Marshal(hb)
		req, _ := http.NewRequest(http.MethodPost, master+"/api/node/heartbeat", strings.NewReader(string(b)))
		req.Header.Set("Authorization", "Bearer "+token)
		req.Header.Set("Content-Type", "application/json")
		_, _ = (&http.Client{Timeout: 5 * time.Second}).Do(req)
		time.Sleep(10 * time.Second)
	}
}

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
func env(k, d string) string {
	if v := os.Getenv(k); v != "" {
		return v
	}
	return d
}
