package main

import (
	"context"
	"crypto/tls"
	"flag"
	"fmt"
	"log"
	"net"
	"net/http"
	"path"
	"runtime"
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

	httpSrv := &http.Server{Addr: proxy.HTTPAddr(snap.HTTPPort), Handler: handler}
	httpsSrv := &http.Server{Addr: proxy.HTTPAddr(snap.HTTPSPort), Handler: handler, TLSConfig: &tls.Config{GetCertificate: store.GetCertificate, MinVersion: tls.VersionTLS12}}

	startAdminListener(client, manager, store, *nodeSecret, *adminListenAddr)

	go func() {
		ticker := time.NewTicker(*heartbeatInterval)
		defer ticker.Stop()
		for range ticker.C {
			up, down := engine.ConsumeTraffic()
			var mem runtime.MemStats
			runtime.ReadMemStats(&mem)

			ctx, cancel := context.WithTimeout(context.Background(), 6*time.Second)
			if err := client.SendHeartbeat(ctx, config.HeartbeatPayload{
				CPU:         0,
				RAMMB:       mem.Alloc / 1024 / 1024,
				Goroutines:  runtime.NumGoroutine(),
				TrafficUp:   up,
				TrafficDown: down,
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
