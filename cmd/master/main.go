package main

import (
	"crypto/rand"
	"encoding/hex"
	"encoding/json"
	"fmt"
	"log"
	"math"
	"net"
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

type overloadEvent struct {
	NodeID    string    `json:"node_id"`
	Reason    string    `json:"reason"`
	Count     int       `json:"count"`
	FirstSeen time.Time `json:"first_seen"`
	LastSeen  time.Time `json:"last_seen"`
	Dismissed bool      `json:"dismissed"`
}

type state struct {
	mu            sync.RWMutex
	Nodes         map[string]*nodeState      `json:"nodes"`
	Certs         map[string]common.CertTask `json:"certs"`
	HuaweiDNS     common.HuaweiDNSConfig     `json:"huawei_dns"`
	OverloadTable map[string]*overloadEvent  `json:"overload_table"`
	LastResetYM   string                     `json:"last_reset_ym"`
}

func newState() *state {
	return &state{Nodes: map[string]*nodeState{}, Certs: map[string]common.CertTask{}, OverloadTable: map[string]*overloadEvent{}, LastResetYM: time.Now().Format("2006-01")}
}

func main() {
	listen := env("MASTER_LISTEN", ":8080")
	marker := strings.Trim(env("MASTER_MARKER", "tianyun123"), "/")
	enrollKey := env("MASTER_ENROLL_KEY", "change-me")
	adminUser := env("MASTER_ADMIN_USER", "admin")
	adminPass := env("MASTER_ADMIN_PASS", "admin123")
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
		if r.FormValue("username") != adminUser || r.FormValue("password") != adminPass {
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
	mux.HandleFunc(p+"/certs", authPage(sessions, p, func(w http.ResponseWriter, _ *http.Request) {
		w.Header().Set("Content-Type", "text/html; charset=utf-8")
		_, _ = w.Write([]byte(certsPage))
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
			http.Error(w, "node_id required", 400)
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
			http.Error(w, "unauthorized", 401)
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
			http.Error(w, "unauthorized", 401)
			return
		}
		hb.Timestamp = time.Now()
		n.Heartbeat = hb
		if reason := overloadReason(hb); reason != "" {
			key := hb.NodeID + ":" + reason
			ev := st.OverloadTable[key]
			if ev == nil {
				ev = &overloadEvent{NodeID: hb.NodeID, Reason: reason, FirstSeen: time.Now()}
				st.OverloadTable[key] = ev
			}
			ev.Count++
			ev.LastSeen = time.Now()
		}
		w.WriteHeader(204)
	})

	mux.HandleFunc(p+"/api/node/cert", func(w http.ResponseWriter, r *http.Request) {
		var task common.CertTask
		_ = json.NewDecoder(r.Body).Decode(&task)
		if task.Domain == "" || task.ManagedBy == "" {
			http.Error(w, "bad request", 400)
			return
		}
		auth := strings.TrimPrefix(r.Header.Get("Authorization"), "Bearer ")
		st.mu.RLock()
		n := st.Nodes[task.ManagedBy]
		st.mu.RUnlock()
		if n == nil || n.Token != auth {
			http.Error(w, "unauthorized", 401)
			return
		}
		task.UpdatedAt = time.Now()
		st.mu.Lock()
		st.Certs[task.Domain] = mergeCertTask(st.Certs[task.Domain], task)
		st.mu.Unlock()
		w.WriteHeader(204)
	})

	mux.HandleFunc(p+"/api/admin/node", func(w http.ResponseWriter, r *http.Request) {
		if !isAdmin(r, adminToken, sessions) || r.Method != http.MethodPut {
			http.Error(w, "forbidden", 403)
			return
		}
		var cfg common.AgentConfig
		_ = json.NewDecoder(r.Body).Decode(&cfg)
		if cfg.NodeID == "" {
			http.Error(w, "node_id required", 400)
			return
		}
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
		w.WriteHeader(204)
	})

	mux.HandleFunc(p+"/api/admin/node/retest", func(w http.ResponseWriter, r *http.Request) {
		if !isAdmin(r, adminToken, sessions) || r.Method != http.MethodPost {
			http.Error(w, "forbidden", 403)
			return
		}
		nodeID := r.URL.Query().Get("node_id")
		st.mu.Lock()
		defer st.mu.Unlock()
		n := st.Nodes[nodeID]
		if n == nil {
			http.Error(w, "node not found", 404)
			return
		}
		n.Config.RequestSpeedtest = true
		n.Config.UpdatedAt = time.Now()
		w.WriteHeader(204)
	})

	mux.HandleFunc(p+"/api/admin/certs", func(w http.ResponseWriter, r *http.Request) {
		if !isAdmin(r, adminToken, sessions) {
			http.Error(w, "forbidden", 403)
			return
		}
		switch r.Method {
		case http.MethodGet:
			st.mu.RLock()
			arr := make([]common.CertTask, 0, len(st.Certs))
			for _, c := range st.Certs {
				arr = append(arr, c)
			}
			st.mu.RUnlock()
			sort.Slice(arr, func(i, j int) bool { return arr[i].UpdatedAt.After(arr[j].UpdatedAt) })
			_ = json.NewEncoder(w).Encode(arr)
		case http.MethodPost:
			var t common.CertTask
			_ = json.NewDecoder(r.Body).Decode(&t)
			if t.Domain == "" {
				http.Error(w, "domain required", 400)
				return
			}
			if t.Method == "" {
				t.Method = common.CertMethodHTTP
			}
			t.UpdatedAt = time.Now()
			t.Status = "pending"
			if t.Method == common.CertMethodHTTP {
				t.Files = []common.ACMEChallengeFile{{Domain: t.Domain, Token: token(12), Content: token(18), UpdatedAt: time.Now()}}
				t.Status = "pending_http_sync"
			}
			if t.Method == common.CertMethodDNS {
				t.DNSName = "_acme-challenge." + t.Domain
				t.DNSValue = "cfrelay-" + token(10)
				t.Status = "pending_dns_user"
			}
			st.mu.Lock()
			t.ManagedBy = "master"
			st.Certs[t.Domain] = t
			if t.Method == common.CertMethodHTTP {
				st.distributeHTTPChallengeLocked(t.Domain)
			}
			for id, n := range st.Nodes {
				n.Config.CertRetryDomains = appendUnique(n.Config.CertRetryDomains, t.Domain)
				n.Config.UpdatedAt = time.Now()
				_ = id
			}
			st.mu.Unlock()
			w.WriteHeader(204)
		default:
			http.Error(w, "method", 405)
		}
	})

	mux.HandleFunc(p+"/api/admin/certs/verify", func(w http.ResponseWriter, r *http.Request) {
		if !isAdmin(r, adminToken, sessions) || r.Method != http.MethodPost {
			http.Error(w, "forbidden", 403)
			return
		}
		domain := r.URL.Query().Get("domain")
		st.mu.Lock()
		defer st.mu.Unlock()
		t := st.Certs[domain]
		if t.Domain == "" {
			http.Error(w, "task not found", 404)
			return
		}
		if t.Method == common.CertMethodDNS {
			txts, _ := net.LookupTXT(t.DNSName)
			ok := false
			for _, v := range txts {
				if strings.TrimSpace(v) == strings.TrimSpace(t.DNSValue) {
					ok = true
					break
				}
			}
			if ok {
				t.LocalVerified = true
				t.Status = "local_verified"
			} else {
				t.Status = "dns_not_propagated"
				t.LastError = "TXT not found"
			}
		}
		if t.Method == common.CertMethodHTTP {
			t.Status = "local_verified"
			t.LocalVerified = true
			st.distributeHTTPChallengeLocked(domain)
		}
		t.UpdatedAt = time.Now()
		st.Certs[domain] = t
		w.WriteHeader(204)
	})

	mux.HandleFunc(p+"/api/admin/certs/retry", func(w http.ResponseWriter, r *http.Request) {
		if !isAdmin(r, adminToken, sessions) || r.Method != http.MethodPost {
			http.Error(w, "forbidden", 403)
			return
		}
		domain := r.URL.Query().Get("domain")
		st.mu.Lock()
		defer st.mu.Unlock()
		t := st.Certs[domain]
		if t.Domain == "" {
			http.Error(w, "task not found", 404)
			return
		}
		t.RetryCount++
		t.Status = "retrying"
		t.UpdatedAt = time.Now()
		st.Certs[domain] = t
		if t.Method == common.CertMethodHTTP {
			st.distributeHTTPChallengeLocked(domain)
		}
		for _, n := range st.Nodes {
			n.Config.CertRetryDomains = appendUnique(n.Config.CertRetryDomains, domain)
			n.Config.UpdatedAt = time.Now()
		}
		w.WriteHeader(204)
	})

	mux.HandleFunc(p+"/api/admin/overloads", func(w http.ResponseWriter, r *http.Request) {
		if !isAdmin(r, adminToken, sessions) {
			http.Error(w, "forbidden", 403)
			return
		}
		if r.Method == http.MethodPost {
			key := r.URL.Query().Get("key")
			st.mu.Lock()
			if ev := st.OverloadTable[key]; ev != nil {
				ev.Dismissed = true
			}
			st.mu.Unlock()
			w.WriteHeader(204)
			return
		}
		st.mu.RLock()
		out := make([]*overloadEvent, 0, len(st.OverloadTable))
		for _, ev := range st.OverloadTable {
			out = append(out, ev)
		}
		st.mu.RUnlock()
		sort.Slice(out, func(i, j int) bool { return out[i].LastSeen.After(out[j].LastSeen) })
		_ = json.NewEncoder(w).Encode(out)
	})

	mux.HandleFunc(p+"/api/admin/overview", func(w http.ResponseWriter, r *http.Request) {
		if !isAdmin(r, adminToken, sessions) {
			http.Error(w, "forbidden", 403)
			return
		}
		type row struct {
			NodeID string               `json:"node_id"`
			HB     common.NodeHeartbeat `json:"heartbeat"`
			CFG    common.AgentConfig   `json:"config"`
		}
		type ov struct {
			Rows                  []row     `json:"rows"`
			TotalCPU              float64   `json:"total_cpu"`
			TotalMem              float64   `json:"total_mem"`
			TotalBW               float64   `json:"total_bw"`
			UsedBW                float64   `json:"used_bw"`
			NodesNeedingScaleHint bool      `json:"nodes_needing_scale_hint"`
			Timestamp             time.Time `json:"timestamp"`
		}
		st.mu.RLock()
		defer st.mu.RUnlock()
		o := ov{Timestamp: time.Now()}
		for id, n := range st.Nodes {
			o.Rows = append(o.Rows, row{NodeID: id, HB: n.Heartbeat, CFG: n.Config})
			o.TotalCPU += n.Heartbeat.CPUPercent
			o.TotalMem += n.Heartbeat.MemPercent
			o.TotalBW += n.Heartbeat.MaxBandwidth
			o.UsedBW += n.Heartbeat.BandwidthMbps
		}
		if len(o.Rows) > 0 {
			full := 0
			for _, r := range o.Rows {
				if overloadReason(r.HB) != "" {
					full++
				}
			}
			o.NodesNeedingScaleHint = full == len(o.Rows)
		}
		_ = json.NewEncoder(w).Encode(o)
	})

	mux.HandleFunc(p+"/api/admin/nodes", func(w http.ResponseWriter, r *http.Request) {
		if !isAdmin(r, adminToken, sessions) {
			http.Error(w, "forbidden", 403)
			return
		}
		if r.Method != http.MethodGet {
			http.Error(w, "method", 405)
			return
		}
		type row struct {
			NodeID    string               `json:"node_id"`
			Heartbeat common.NodeHeartbeat `json:"heartbeat"`
			Config    common.AgentConfig   `json:"config"`
		}
		resp := make([]row, 0)
		st.mu.RLock()
		for id, n := range st.Nodes {
			resp = append(resp, row{NodeID: id, Heartbeat: n.Heartbeat, Config: n.Config})
		}
		st.mu.RUnlock()
		sort.Slice(resp, func(i, j int) bool { return resp[i].NodeID < resp[j].NodeID })
		_ = json.NewEncoder(w).Encode(resp)
	})

	mux.HandleFunc(p+"/api/admin/dns/huawei", func(w http.ResponseWriter, r *http.Request) {
		if !isAdmin(r, adminToken, sessions) {
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
			w.WriteHeader(204)
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

func mergeCertTask(old, new common.CertTask) common.CertTask {
	if old.Domain == "" {
		return new
	}
	if new.Method == "" {
		new.Method = old.Method
	}
	if len(new.Files) == 0 {
		new.Files = old.Files
	}
	if new.DNSName == "" {
		new.DNSName = old.DNSName
	}
	if new.DNSValue == "" {
		new.DNSValue = old.DNSValue
	}
	if new.RetryCount == 0 {
		new.RetryCount = old.RetryCount
	}
	if !new.LocalVerified {
		new.LocalVerified = old.LocalVerified
	}
	return new
}

func (s *state) distributeHTTPChallengeLocked(domain string) {
	t := s.Certs[domain]
	if len(t.Files) == 0 {
		t.Files = []common.ACMEChallengeFile{{Domain: domain, Token: token(12), Content: token(18), UpdatedAt: time.Now()}}
		s.Certs[domain] = t
	}
	for _, n := range s.Nodes {
		for _, f := range t.Files {
			n.Config.ACMEChallengeFiles = appendChallenge(n.Config.ACMEChallengeFiles, f)
		}
		n.Config.UpdatedAt = time.Now()
	}
}

func appendChallenge(arr []common.ACMEChallengeFile, v common.ACMEChallengeFile) []common.ACMEChallengeFile {
	for i := range arr {
		if arr[i].Token == v.Token && arr[i].Domain == v.Domain {
			arr[i] = v
			return arr
		}
	}
	return append(arr, v)
}
func appendUnique(arr []string, v string) []string {
	for _, a := range arr {
		if strings.EqualFold(a, v) {
			return arr
		}
	}
	return append(arr, v)
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
	body := map[string]any{"zone_id": cfg.ZoneID, "recordset_id": cfg.RecordsetID, "scheduler_cname": cfg.SchedulerCNAME, "weights": weights}
	if cfg.Endpoint != "" {
		b, _ := json.Marshal(body)
		req, _ := http.NewRequest(http.MethodPost, cfg.Endpoint, strings.NewReader(string(b)))
		req.Header.Set("Content-Type", "application/json")
		req.Header.Set("X-Access-Key", cfg.AccessKey)
		req.Header.Set("X-Secret-Key", cfg.SecretKey)
		resp, err := (&http.Client{Timeout: 8 * time.Second}).Do(req)
		if err != nil {
			return nil, err
		}
		defer resp.Body.Close()
		if resp.StatusCode >= 300 {
			return nil, fmt.Errorf("dns sync failed status=%d", resp.StatusCode)
		}
	}
	return map[string]any{"ok": true, "weights": weights}, nil
}
func (s *state) schedulerLoop(path string) {
	for range time.Tick(12 * time.Second) {
		_ = s.save(path)
		_ = s.maybeMonthlyReset()
		_, _ = s.syncHuaweiDNS()
	}
}
func (s *state) maybeMonthlyReset() error {
	ym := time.Now().Format("2006-01")
	s.mu.Lock()
	defer s.mu.Unlock()
	if s.LastResetYM == ym {
		return nil
	}
	for _, n := range s.Nodes {
		n.Config.Paused = false
		n.Config.UpdatedAt = time.Now()
	}
	s.LastResetYM = ym
	return nil
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
	return ""
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
			http.Redirect(w, r, p+"/login", 302)
			return
		}
		if _, ok := s.Load(c.Value); !ok {
			http.Redirect(w, r, p+"/login", 302)
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
	if s.OverloadTable == nil {
		s.OverloadTable = map[string]*overloadEvent{}
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

const loginPage = `<!doctype html><html><head><meta charset="utf-8"/><meta name="viewport" content="width=device-width, initial-scale=1"/><title>CFRelay 登录</title><style>
body{margin:0;font-family:Inter,Segoe UI,Arial;background:linear-gradient(135deg,#0f172a,#1e293b);height:100vh;display:flex;align-items:center;justify-content:center;color:#0f172a}
.card{width:360px;background:#ffffff;border-radius:16px;box-shadow:0 20px 60px rgba(2,6,23,.45);padding:26px}
.logo{font-weight:700;font-size:20px;margin:0 0 4px}
.sub{color:#64748b;font-size:13px;margin-bottom:18px}
label{display:block;font-size:12px;color:#334155;margin:10px 0 6px}
input{width:100%;box-sizing:border-box;border:1px solid #cbd5e1;border-radius:10px;padding:10px 12px;font-size:14px;outline:none}
input:focus{border-color:#2563eb;box-shadow:0 0 0 3px rgba(37,99,235,.15)}
button{margin-top:14px;width:100%;border:0;background:#2563eb;color:#fff;border-radius:10px;padding:10px 12px;font-weight:600;cursor:pointer}
.tip{margin-top:10px;font-size:12px;color:#64748b}
</style></head><body><form class="card" method="post"><p class="logo">CFRelay 控制台</p><div class="sub">请输入主控账号密码登录</div><label>账号</label><input name="username" placeholder="admin" required/><label>密码</label><input name="password" type="password" placeholder="••••••••" required/><button>登录</button><div class="tip">默认账号：admin / admin123</div></form></body></html>`
const dashboardPage = `<!doctype html><html><head><meta charset="utf-8"/><meta name="viewport" content="width=device-width, initial-scale=1"/><title>CFRelay Dashboard</title><style>
body{margin:0;font-family:Inter,Segoe UI,Arial;background:#0b1220;color:#e2e8f0}.wrap{max-width:1180px;margin:0 auto;padding:18px}.top{display:flex;justify-content:space-between;align-items:center}.btn{background:#2563eb;color:#fff;border:0;border-radius:10px;padding:8px 12px;cursor:pointer}.cards{display:grid;grid-template-columns:repeat(3,minmax(0,1fr));gap:12px;margin-top:14px}.card{background:#111a2e;border:1px solid #26334f;border-radius:14px;padding:14px}.muted{color:#94a3b8;font-size:12px}.nodes{display:grid;grid-template-columns:repeat(2,minmax(0,1fr));gap:12px;margin-top:14px}.node{background:#111a2e;border:1px solid #26334f;border-radius:14px;padding:12px}.node h4{margin:0 0 8px}.k{font-size:12px;color:#94a3b8}.bar{height:8px;background:#1f2a44;border-radius:999px;overflow:hidden}.fill{height:8px;background:#22c55e}
@media(max-width:900px){.cards,.nodes{grid-template-columns:1fr}}
</style></head><body><div class='wrap'><div class='top'><h2>CFRelay 控制面板</h2><div><a class='btn' href='./settings'>设置</a> <a class='btn' href='./certs'>证书工单</a></div></div><div id='warn' style='display:none;background:#7f1d1d;border:1px solid #ef4444;padding:10px;border-radius:10px;margin-top:10px'></div><div class='cards'><div class='card'><div>总CPU</div><h3 id='cpu'>0%</h3><div class='muted'>所有节点平均</div></div><div class='card'><div>总内存</div><h3 id='mem'>0%</h3><div class='muted'>所有节点平均</div></div><div class='card'><div>总带宽利用</div><h3 id='bw'>0%</h3><div class='muted'>当前/总上限</div></div></div><h3 style='margin-top:16px'>节点列表</h3><div id='nodes' class='nodes'></div></div><script>
async function load(){const ov=await (await fetch(location.pathname.replace('/dashboard','/api/admin/overview'))).json();const ns=await (await fetch(location.pathname.replace('/dashboard','/api/admin/nodes'))).json();
const avg=(arr,key)=>arr.length?arr.reduce((a,b)=>a+(b.heartbeat[key]||0),0)/arr.length:0;cpu.textContent=avg(ns,'cpu_percent').toFixed(1)+'%';mem.textContent=avg(ns,'mem_percent').toFixed(1)+'%';const bwp=ov.total_bw?ov.used_bw*100/ov.total_bw:0;bw.textContent=bwp.toFixed(1)+'%';
warn.style.display=ov.nodes_needing_scale_hint?'block':'none';warn.textContent='所有节点接近满载，建议新增节点。';
nodes.innerHTML=ns.map(function(n){return '<div class="node"><h4>'+n.node_id+'</h4><div class="k">CPU '+n.heartbeat.cpu_percent.toFixed(1)+'%</div><div class="bar"><div class="fill" style="width:'+Math.min(100,n.heartbeat.cpu_percent)+'%"></div></div><div class="k">MEM '+n.heartbeat.mem_percent.toFixed(1)+'%</div><div class="bar"><div class="fill" style="width:'+Math.min(100,n.heartbeat.mem_percent)+'%"></div></div><div class="k">带宽 '+n.heartbeat.bandwidth_mbps.toFixed(1)+'/'+n.heartbeat.max_bandwidth_mbps.toFixed(1)+' Mbps</div><div style="margin-top:8px"><button class="btn" onclick="retest(\''+n.node_id+'\')">重测速</button></div><details style="margin-top:8px"><summary>展开高级配置</summary><pre>'+JSON.stringify(n.config,null,2)+'</pre></details></div>';}).join('');}
async function retest(id){await fetch(location.pathname.replace('/dashboard','/api/admin/node/retest?node_id='+encodeURIComponent(id)),{method:'POST'});alert('已下发重测速到 '+id)}
load();setInterval(load,4000);
</script></body></html>`
const settingsPage = `<!doctype html><html><head><meta charset="utf-8"/><meta name="viewport" content="width=device-width, initial-scale=1"/><title>设置</title><style>body{font-family:Inter,Segoe UI,Arial;background:#0b1220;color:#e2e8f0;margin:0}.wrap{max-width:980px;margin:0 auto;padding:18px}.card{background:#111a2e;border:1px solid #26334f;border-radius:14px;padding:14px;margin-bottom:12px}input,textarea{width:100%;box-sizing:border-box;background:#0b1220;color:#e2e8f0;border:1px solid #334155;border-radius:10px;padding:8px;margin-top:6px}button{background:#2563eb;color:#fff;border:0;border-radius:10px;padding:8px 12px;cursor:pointer}</style></head><body><div class='wrap'><h2>控制台设置</h2><div class='card'><p>提示：端口/marker/账号密码属于服务环境变量，修改后通过安装脚本会立即重启生效。</p></div><div class='card'><h3>华为云DNS调度</h3><label>Endpoint<input id='ep'/></label><label>Zone ID<input id='zid'/></label><label>RecordSet ID<input id='rid'/></label><label>Scheduler CNAME<input id='cname'/></label><button onclick='saveDNS()'>保存</button> <button onclick='runDNS()'>立即同步</button><pre id='out'></pre></div><a href='./dashboard' style='color:#93c5fd'>返回仪表盘</a></div><script>
async function init(){const x=await (await fetch(location.pathname.replace('/settings','/api/admin/dns/huawei'))).json();ep.value=x.endpoint||'';zid.value=x.zone_id||'';rid.value=x.recordset_id||'';cname.value=x.scheduler_cname||''}
async function saveDNS(){await fetch(location.pathname.replace('/settings','/api/admin/dns/huawei'),{method:'PUT',headers:{'Content-Type':'application/json'},body:JSON.stringify({enabled:true,endpoint:ep.value,zone_id:zid.value,recordset_id:rid.value,scheduler_cname:cname.value})});out.textContent='保存成功'}
async function runDNS(){const r=await fetch(location.pathname.replace('/settings','/api/admin/dns/huawei'),{method:'POST'});out.textContent=await r.text()}
init();
</script></body></html>`
const certsPage = `<!doctype html><html><body><h2>证书工单</h2><p>支持HTTP与DNS验证工单、重试和本地验证。</p><div><input id='d' placeholder='domain'/><select id='m'><option value='http'>http</option><option value='dns'>dns</option></select><button onclick='create()'>创建工单</button></div><pre id='out'>loading...</pre><script>
async function list(){const r=await fetch(location.pathname.replace('/certs','/api/admin/certs'));const j=await r.json();out.textContent=JSON.stringify(j,null,2)}
async function create(){await fetch(location.pathname.replace('/certs','/api/admin/certs'),{method:'POST',headers:{'Content-Type':'application/json'},body:JSON.stringify({domain:d.value,method:m.value})});await list()}
list();setInterval(list,5000)
</script></body></html>`
