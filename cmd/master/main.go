package main

import (
	"crypto/rand"
	"encoding/hex"
	"encoding/json"
	"log"
	"net/http"
	"os"
	"path/filepath"
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

type store struct {
	mu    sync.RWMutex
	Nodes map[string]*nodeState `json:"nodes"`
}

func newStore() *store { return &store{Nodes: map[string]*nodeState{}} }

func token() string {
	b := make([]byte, 16)
	_, _ = rand.Read(b)
	return hex.EncodeToString(b)
}

func (s *store) save(path string) error {
	s.mu.RLock()
	defer s.mu.RUnlock()
	b, _ := json.MarshalIndent(s, "", "  ")
	return os.WriteFile(path, b, 0o644)
}

func (s *store) load(path string) {
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
}

func main() {
	listen := env("MASTER_LISTEN", ":8080")
	marker := strings.Trim(env("MASTER_MARKER", "tianyun123"), "/")
	enrollKey := env("MASTER_ENROLL_KEY", "change-me")
	dataPath := env("MASTER_DATA", filepath.Join("data", "master.json"))
	_ = os.MkdirAll(filepath.Dir(dataPath), 0o755)

	st := newStore()
	st.load(dataPath)

	mux := http.NewServeMux()
	prefix := "/" + marker
	mux.HandleFunc(prefix+"/healthz", func(w http.ResponseWriter, r *http.Request) { w.Write([]byte("ok")) })

	mux.HandleFunc(prefix+"/api/enroll", func(w http.ResponseWriter, r *http.Request) {
		if r.Method != http.MethodPost {
			http.Error(w, "method", http.StatusMethodNotAllowed)
			return
		}
		if r.Header.Get("X-Enroll-Key") != enrollKey {
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
		ns := st.Nodes[req.NodeID]
		if ns == nil {
			ns = &nodeState{}
			st.Nodes[req.NodeID] = ns
		}
		ns.Token = token()
		ns.Config.NodeID = req.NodeID
		ns.Config.UpdatedAt = time.Now()
		st.mu.Unlock()
		_ = st.save(dataPath)
		json.NewEncoder(w).Encode(map[string]string{"token": ns.Token})
	})

	mux.HandleFunc(prefix+"/api/node/config", func(w http.ResponseWriter, r *http.Request) {
		nodeID := r.URL.Query().Get("node_id")
		auth := strings.TrimPrefix(r.Header.Get("Authorization"), "Bearer ")
		st.mu.RLock()
		ns := st.Nodes[nodeID]
		st.mu.RUnlock()
		if ns == nil || ns.Token != auth {
			http.Error(w, "unauthorized", http.StatusUnauthorized)
			return
		}
		json.NewEncoder(w).Encode(ns.Config)
	})

	mux.HandleFunc(prefix+"/api/node/heartbeat", func(w http.ResponseWriter, r *http.Request) {
		if r.Method != http.MethodPost {
			http.Error(w, "method", http.StatusMethodNotAllowed)
			return
		}
		var hb common.NodeHeartbeat
		_ = json.NewDecoder(r.Body).Decode(&hb)
		auth := strings.TrimPrefix(r.Header.Get("Authorization"), "Bearer ")
		st.mu.Lock()
		defer st.mu.Unlock()
		ns := st.Nodes[hb.NodeID]
		if ns == nil || ns.Token != auth {
			http.Error(w, "unauthorized", http.StatusUnauthorized)
			return
		}
		hb.Timestamp = time.Now()
		ns.Heartbeat = hb
		w.WriteHeader(http.StatusNoContent)
	})

	mux.HandleFunc(prefix+"/api/admin/node", func(w http.ResponseWriter, r *http.Request) {
		if r.Method != http.MethodPut {
			http.Error(w, "method", http.StatusMethodNotAllowed)
			return
		}
		if r.Header.Get("X-Admin-Token") != env("MASTER_ADMIN_TOKEN", "admin-change-me") {
			http.Error(w, "forbidden", http.StatusForbidden)
			return
		}
		var cfg common.AgentConfig
		_ = json.NewDecoder(r.Body).Decode(&cfg)
		if cfg.NodeID == "" {
			http.Error(w, "node_id required", http.StatusBadRequest)
			return
		}
		st.mu.Lock()
		ns := st.Nodes[cfg.NodeID]
		if ns == nil {
			ns = &nodeState{Token: token()}
			st.Nodes[cfg.NodeID] = ns
		}
		cfg.UpdatedAt = time.Now()
		ns.Config = cfg
		st.mu.Unlock()
		_ = st.save(dataPath)
		w.WriteHeader(http.StatusNoContent)
	})

	mux.HandleFunc(prefix+"/api/admin/overview", func(w http.ResponseWriter, r *http.Request) {
		if r.Header.Get("X-Admin-Token") != env("MASTER_ADMIN_TOKEN", "admin-change-me") {
			http.Error(w, "forbidden", http.StatusForbidden)
			return
		}
		type row struct {
			NodeID string               `json:"node_id"`
			HB     common.NodeHeartbeat `json:"heartbeat"`
		}
		resp := []row{}
		st.mu.RLock()
		for id, ns := range st.Nodes {
			resp = append(resp, row{NodeID: id, HB: ns.Heartbeat})
		}
		st.mu.RUnlock()
		json.NewEncoder(w).Encode(resp)
	})

	log.Printf("master listen=%s marker=/%s", listen, marker)
	log.Fatal(http.ListenAndServe(listen, logReq(mux)))
}

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
