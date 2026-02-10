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
			Rows                                []row     `json:"rows"`
			TotalCPU, TotalMem, TotalBW, UsedBW float64   `json:"total_cpu,total_mem,total_bw,used_bw"`
			NodesNeedingScaleHint               bool      `json:"nodes_needing_scale_hint"`
			Timestamp                           time.Time `json:"timestamp"`
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
const dashboardPage = `<!doctype html><html><body><h2>CFRelay Dashboard</h2><a href='./certs'>证书工单</a><div style='display:flex;gap:16px'><canvas id='cpu' width='120' height='120'></canvas><canvas id='mem' width='120' height='120'></canvas><canvas id='bw' width='120' height='120'></canvas></div><div id='hint' style='color:red'></div><div id='ov'></div><script>
function pie(id,p,t){const c=document.getElementById(id),x=c.getContext('2d');x.clearRect(0,0,120,120);x.beginPath();x.moveTo(60,60);x.fillStyle='#4caf50';x.arc(60,60,55,-Math.PI/2,-Math.PI/2+Math.PI*2*(p/100));x.fill();x.beginPath();x.moveTo(60,60);x.fillStyle='#ddd';x.arc(60,60,55,-Math.PI/2+Math.PI*2*(p/100),1.5*Math.PI);x.fill();x.fillStyle='#111';x.fillText(t+': '+p.toFixed(1)+'%',20,115)}
async function load(){const r=await fetch(location.pathname.replace('/dashboard','/api/admin/overview'));const j=await r.json();const cpu=(j.rows.length?j.total_cpu/j.rows.length:0),mem=(j.rows.length?j.total_mem/j.rows.length:0),bw=(j.total_bw?j.used_bw*100/j.total_bw:0);pie('cpu',cpu,'CPU');pie('mem',mem,'MEM');pie('bw',bw,'BW');hint.textContent=j.nodes_needing_scale_hint?'所有节点接近满载，建议新增节点':'';ov.innerHTML=j.rows.map(function(n){return '<details><summary>'+n.node_id+' | CPU '+n.heartbeat.cpu_percent.toFixed(1)+'% | MEM '+n.heartbeat.mem_percent.toFixed(1)+'% | BW '+n.heartbeat.bandwidth_mbps.toFixed(1)+'/'+n.heartbeat.max_bandwidth_mbps.toFixed(1)+' Mbps</summary><pre>'+JSON.stringify(n,null,2)+'</pre></details>'}).join('');}
load();setInterval(load,3000);
</script></body></html>`
const settingsPage = `<!doctype html><html><body><h2>Settings</h2><p>可通过 systemd 环境变量修改端口和 marker。配置 API: /api/admin/node /api/admin/dns/huawei /api/admin/certs</p></body></html>`
const certsPage = `<!doctype html><html><body><h2>证书工单</h2><p>支持HTTP与DNS验证工单、重试和本地验证。</p><div><input id='d' placeholder='domain'/><select id='m'><option value='http'>http</option><option value='dns'>dns</option></select><button onclick='create()'>创建工单</button></div><pre id='out'>loading...</pre><script>
async function list(){const r=await fetch(location.pathname.replace('/certs','/api/admin/certs'));const j=await r.json();out.textContent=JSON.stringify(j,null,2)}
async function create(){await fetch(location.pathname.replace('/certs','/api/admin/certs'),{method:'POST',headers:{'Content-Type':'application/json'},body:JSON.stringify({domain:d.value,method:m.value})});await list()}
list();setInterval(list,5000)
</script></body></html>`
