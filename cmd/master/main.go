package main

import (
	"crypto/rand"
	"encoding/hex"
	"encoding/json"
	"fmt"
	"log"
	"math"
	"net/http"
	"os"
	"path/filepath"
	"sort"
	"strings"
	"sync"
	"time"

	"cfrelay/internal/common"
)

type nodeState struct {
	Token     string               `json:"token"`
	Config    common.AgentConfig   `json:"config"`
	Heartbeat common.NodeHeartbeat `json:"heartbeat"`
}

type state struct {
	mu         sync.RWMutex
	Nodes      map[string]*nodeState      `json:"nodes"`
	Certs      map[string]common.CertTask `json:"certs"`
	HuaweiDNS  common.HuaweiDNSConfig     `json:"huawei_dns"`
	Overloads  []common.NodeHeartbeat     `json:"overloads"`
	LastSynced time.Time                  `json:"last_synced"`
}

func newState() *state {
	return &state{Nodes: map[string]*nodeState{}, Certs: map[string]common.CertTask{}}
}

func main() {
	listen := env("MASTER_LISTEN", ":8080")
	marker := strings.Trim(env("MASTER_MARKER", "tianyun123"), "/")
	enrollKey := env("MASTER_ENROLL_KEY", "change-me")
	adminUser := env("MASTER_ADMIN_USER", "admin")
	adminPass := env("MASTER_ADMIN_PASS", "change-me")
	adminToken := env("MASTER_ADMIN_TOKEN", "admin-change-me")
	dataPath := env("MASTER_DATA", filepath.Join("data", "master.json"))
	_ = os.MkdirAll(filepath.Dir(dataPath), 0o755)

	st := newState()
	st.load(dataPath)
	go st.schedulerLoop(dataPath)

	sessions := &sync.Map{}
	mux := http.NewServeMux()
	p := "/" + marker

	mux.HandleFunc(p+"/healthz", func(w http.ResponseWriter, _ *http.Request) { _, _ = w.Write([]byte("ok")) })
	mux.HandleFunc(p+"/login", func(w http.ResponseWriter, r *http.Request) {
		if r.Method == http.MethodGet {
			w.Header().Set("Content-Type", "text/html; charset=utf-8")
			_, _ = w.Write([]byte(loginPage))
			return
		}
		user, pass := r.FormValue("username"), r.FormValue("password")
		if user != adminUser || pass != adminPass {
			http.Error(w, "invalid credentials", http.StatusUnauthorized)
			return
		}
		sid := token(24)
		sessions.Store(sid, true)
		http.SetCookie(w, &http.Cookie{Name: "sid", Value: sid, Path: "/", HttpOnly: true, MaxAge: 86400})
		http.Redirect(w, r, p+"/dashboard", http.StatusFound)
	})

	mux.HandleFunc(p+"/dashboard", authPage(sessions, p, func(w http.ResponseWriter, _ *http.Request) {
		w.Header().Set("Content-Type", "text/html; charset=utf-8")
		_, _ = w.Write([]byte(dashboardPage))
	}))
	mux.HandleFunc(p+"/settings", authPage(sessions, p, func(w http.ResponseWriter, _ *http.Request) {
		w.Header().Set("Content-Type", "text/html; charset=utf-8")
		_, _ = w.Write([]byte(settingsPage))
	}))

	mux.HandleFunc(p+"/api/enroll", func(w http.ResponseWriter, r *http.Request) {
		if r.Method != http.MethodPost || r.Header.Get("X-Enroll-Key") != enrollKey {
			http.Error(w, "forbidden", http.StatusForbidden)
			return
		}
		var req struct {
			NodeID string `json:"node_id"`
		}
		_ = json.NewDecoder(r.Body).Decode(&req)
		if req.NodeID == "" {
			http.Error(w, "node_id required", http.StatusBadRequest)
			return
		}
		st.mu.Lock()
		n := st.Nodes[req.NodeID]
		if n == nil {
			n = &nodeState{}
			st.Nodes[req.NodeID] = n
		}
		n.Token = token(16)
		n.Config.NodeID = req.NodeID
		n.Config.AutoCertWhenEmpty = true
		n.Config.TrafficWarnPercent = 10
		n.Config.TrafficMode = common.TrafficModeBoth
		n.Config.UpdatedAt = time.Now()
		st.mu.Unlock()
		_ = st.save(dataPath)
		_ = json.NewEncoder(w).Encode(map[string]string{"token": n.Token})
	})

	mux.HandleFunc(p+"/api/node/config", func(w http.ResponseWriter, r *http.Request) {
		nodeID := r.URL.Query().Get("node_id")
		auth := strings.TrimPrefix(r.Header.Get("Authorization"), "Bearer ")
		st.mu.RLock()
		n := st.Nodes[nodeID]
		st.mu.RUnlock()
		if n == nil || n.Token != auth {
			http.Error(w, "unauthorized", http.StatusUnauthorized)
			return
		}
		_ = json.NewEncoder(w).Encode(n.Config)
	})

	mux.HandleFunc(p+"/api/node/heartbeat", func(w http.ResponseWriter, r *http.Request) {
		var hb common.NodeHeartbeat
		_ = json.NewDecoder(r.Body).Decode(&hb)
		auth := strings.TrimPrefix(r.Header.Get("Authorization"), "Bearer ")
		st.mu.Lock()
		defer st.mu.Unlock()
		n := st.Nodes[hb.NodeID]
		if n == nil || n.Token != auth {
			http.Error(w, "unauthorized", http.StatusUnauthorized)
			return
		}
		hb.Timestamp = time.Now()
		if hb.CPUPercent >= 95 || hb.MemPercent >= 95 || (hb.MaxBandwidth > 0 && hb.BandwidthMbps/hb.MaxBandwidth >= 0.95) {
			hb.OverloadReason = overloadReason(hb)
			st.Overloads = append(st.Overloads, hb)
		}
		n.Heartbeat = hb
		w.WriteHeader(http.StatusNoContent)
	})

	mux.HandleFunc(p+"/api/node/cert", func(w http.ResponseWriter, r *http.Request) {
		var task common.CertTask
		_ = json.NewDecoder(r.Body).Decode(&task)
		if task.Domain == "" || task.ManagedBy == "" {
			http.Error(w, "bad request", http.StatusBadRequest)
			return
		}
		auth := strings.TrimPrefix(r.Header.Get("Authorization"), "Bearer ")
		st.mu.RLock()
		n := st.Nodes[task.ManagedBy]
		st.mu.RUnlock()
		if n == nil || n.Token != auth {
			http.Error(w, "unauthorized", http.StatusUnauthorized)
			return
		}
		task.UpdatedAt = time.Now()
		st.mu.Lock()
		st.Certs[task.Domain] = task
		st.mu.Unlock()
		w.WriteHeader(http.StatusNoContent)
	})

	mux.HandleFunc(p+"/api/admin/node", func(w http.ResponseWriter, r *http.Request) {
		if r.Header.Get("X-Admin-Token") != adminToken || r.Method != http.MethodPut {
			http.Error(w, "forbidden", http.StatusForbidden)
			return
		}
		var cfg common.AgentConfig
		_ = json.NewDecoder(r.Body).Decode(&cfg)
		st.mu.Lock()
		n := st.Nodes[cfg.NodeID]
		if n == nil {
			n = &nodeState{Token: token(16)}
			st.Nodes[cfg.NodeID] = n
		}
		cfg.UpdatedAt = time.Now()
		n.Config = cfg
		st.mu.Unlock()
		_ = st.save(dataPath)
		w.WriteHeader(http.StatusNoContent)
	})

	mux.HandleFunc(p+"/api/admin/certs", func(w http.ResponseWriter, r *http.Request) {
		if !isAdmin(r, adminToken, sessions) {
			http.Error(w, "forbidden", 403)
			return
		}
		if r.Method == http.MethodPost {
			var t common.CertTask
			_ = json.NewDecoder(r.Body).Decode(&t)
			t.Status = "pending"
			t.UpdatedAt = time.Now()
			st.mu.Lock()
			st.Certs[t.Domain] = t
			st.mu.Unlock()
			w.WriteHeader(http.StatusNoContent)
			return
		}
		st.mu.RLock()
		defer st.mu.RUnlock()
		arr := make([]common.CertTask, 0, len(st.Certs))
		for _, c := range st.Certs {
			arr = append(arr, c)
		}
		sort.Slice(arr, func(i, j int) bool { return arr[i].UpdatedAt.After(arr[j].UpdatedAt) })
		_ = json.NewEncoder(w).Encode(arr)
	})

	mux.HandleFunc(p+"/api/admin/overview", func(w http.ResponseWriter, r *http.Request) {
		if !isAdmin(r, adminToken, sessions) {
			http.Error(w, "forbidden", 403)
			return
		}
		type ov struct {
			Nodes         map[string]*nodeState  `json:"nodes"`
			TotalCPU      float64                `json:"total_cpu"`
			TotalMem      float64                `json:"total_mem"`
			TotalBW       float64                `json:"total_bw"`
			UsedBW        float64                `json:"used_bw"`
			OverloadCount int                    `json:"overload_count"`
			CertCount     int                    `json:"cert_count"`
			Overloads     []common.NodeHeartbeat `json:"overloads"`
		}
		st.mu.RLock()
		defer st.mu.RUnlock()
		o := ov{Nodes: st.Nodes, OverloadCount: len(st.Overloads), CertCount: len(st.Certs)}
		for _, n := range st.Nodes {
			o.TotalCPU += n.Heartbeat.CPUPercent
			o.TotalMem += n.Heartbeat.MemPercent
			o.TotalBW += n.Heartbeat.MaxBandwidth
			o.UsedBW += n.Heartbeat.BandwidthMbps
		}
		if len(st.Overloads) > 20 {
			o.Overloads = st.Overloads[len(st.Overloads)-20:]
		} else {
			o.Overloads = st.Overloads
		}
		_ = json.NewEncoder(w).Encode(o)
	})

	mux.HandleFunc(p+"/api/admin/dns/huawei", func(w http.ResponseWriter, r *http.Request) {
		if r.Header.Get("X-Admin-Token") != adminToken {
			http.Error(w, "forbidden", 403)
			return
		}
		if r.Method == http.MethodPut {
			var c common.HuaweiDNSConfig
			_ = json.NewDecoder(r.Body).Decode(&c)
			st.mu.Lock()
			st.HuaweiDNS = c
			st.mu.Unlock()
			_ = st.save(dataPath)
			w.WriteHeader(http.StatusNoContent)
			return
		}
		if r.Method == http.MethodPost {
			res, err := st.syncHuaweiDNS()
			if err != nil {
				http.Error(w, err.Error(), 500)
				return
			}
			_ = json.NewEncoder(w).Encode(res)
			return
		}
		st.mu.RLock()
		defer st.mu.RUnlock()
		_ = json.NewEncoder(w).Encode(st.HuaweiDNS)
	})

	log.Printf("master listen=%s marker=/%s", listen, marker)
	log.Fatal(http.ListenAndServe(listen, logReq(mux)))
}

func (s *state) syncHuaweiDNS() (map[string]any, error) {
	s.mu.RLock()
	cfg := s.HuaweiDNS
	nodes := s.Nodes
	s.mu.RUnlock()
	if !cfg.Enabled {
		return nil, fmt.Errorf("huawei dns disabled")
	}
	weights := map[string]int{}
	for id, n := range nodes {
		score := 100 - int(math.Max(n.Heartbeat.CPUPercent, math.Max(n.Heartbeat.MemPercent, pct(n.Heartbeat.BandwidthMbps, n.Heartbeat.MaxBandwidth))))
		if score > 0 && !n.Config.Paused {
			weights[id] = score
		}
	}
	return map[string]any{"zone_id": cfg.ZoneID, "recordset_id": cfg.RecordsetID, "weights": weights, "note": "ready to call Huawei API"}, nil
}

func (s *state) schedulerLoop(path string) {
	for range time.Tick(12 * time.Second) {
		_, _ = s.syncHuaweiDNS()
		_ = s.save(path)
	}
}

func overloadReason(h common.NodeHeartbeat) string {
	if h.CPUPercent >= 95 {
		return "cpu"
	}
	if h.MemPercent >= 95 {
		return "memory"
	}
	if h.MaxBandwidth > 0 && h.BandwidthMbps/h.MaxBandwidth >= 0.95 {
		return "bandwidth"
	}
	return "unknown"
}

func pct(v, max float64) float64 {
	if max <= 0 {
		return 0
	}
	return (v / max) * 100
}

func isAdmin(r *http.Request, t string, sessions *sync.Map) bool {
	if r.Header.Get("X-Admin-Token") == t {
		return true
	}
	c, err := r.Cookie("sid")
	if err != nil {
		return false
	}
	_, ok := sessions.Load(c.Value)
	return ok
}

func authPage(s *sync.Map, p string, next http.HandlerFunc) http.HandlerFunc {
	return func(w http.ResponseWriter, r *http.Request) {
		c, err := r.Cookie("sid")
		if err != nil {
			http.Redirect(w, r, p+"/login", http.StatusFound)
			return
		}
		if _, ok := s.Load(c.Value); !ok {
			http.Redirect(w, r, p+"/login", http.StatusFound)
			return
		}
		next(w, r)
	}
}

func (s *state) save(path string) error {
	s.mu.RLock()
	defer s.mu.RUnlock()
	b, _ := json.MarshalIndent(s, "", "  ")
	return os.WriteFile(path, b, 0o644)
}
func (s *state) load(path string) {
	b, err := os.ReadFile(path)
	if err != nil {
		return
	}
	s.mu.Lock()
	defer s.mu.Unlock()
	_ = json.Unmarshal(b, s)
	if s.Nodes == nil {
		s.Nodes = map[string]*nodeState{}
	}
	if s.Certs == nil {
		s.Certs = map[string]common.CertTask{}
	}
}
func token(n int) string { b := make([]byte, n); _, _ = rand.Read(b); return hex.EncodeToString(b) }
func env(k, d string) string {
	if v := os.Getenv(k); v != "" {
		return v
	}
	return d
}
func logReq(next http.Handler) http.Handler {
	return http.HandlerFunc(func(w http.ResponseWriter, r *http.Request) {
		log.Printf("%s %s", r.Method, r.URL.Path)
		next.ServeHTTP(w, r)
	})
}

const loginPage = `<!doctype html><html><body><h3>CFRelay Login</h3><form method="post"><input name="username" placeholder="user"/><input name="password" type="password" placeholder="password"/><button>Login</button></form></body></html>`
const dashboardPage = `<!doctype html><html><body><h2>CFRelay Dashboard</h2><pre id='d'>loading...</pre><script>fetch(location.pathname.replace('/dashboard','/api/admin/overview')).then(r=>r.json()).then(j=>d.textContent=JSON.stringify(j,null,2))</script></body></html>`
const settingsPage = `<!doctype html><html><body><h2>Settings</h2><p>Use API with X-Admin-Token to modify marker/ports/service env.</p></body></html>`
