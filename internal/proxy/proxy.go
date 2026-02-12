package proxy

import (
	"context"
	"crypto/tls"
	"fmt"
	"log"
	"net"
	"net/http"
	"net/http/httputil"
	"net/url"
	"strings"
	"sync"
	"sync/atomic"
	"time"

	"github.com/example/cf-proxy/internal/config"
)

type contextKey string

const sniContextKey contextKey = "upstream_sni"

type countingResponseWriter struct {
	http.ResponseWriter
	down *atomic.Uint64
}

func (w *countingResponseWriter) Write(b []byte) (int, error) {
	n, err := w.ResponseWriter.Write(b)
	if n > 0 {
		w.down.Add(uint64(n))
	}
	return n, err
}

type Engine struct {
	manager *config.Manager

	rr atomic.Uint64

	transport http.RoundTripper

	upTraffic   atomic.Uint64
	downTraffic atomic.Uint64

	healthMu   sync.RWMutex
	healthCFIPs []string
}

func New(manager *config.Manager) *Engine {
	base := &http.Transport{
		Proxy: http.ProxyFromEnvironment,
		DialContext: (&net.Dialer{
			Timeout:   5 * time.Second,
			KeepAlive: 30 * time.Second,
		}).DialContext,
		DialTLSContext: func(ctx context.Context, network, addr string) (net.Conn, error) {
			serverName, _ := ctx.Value(sniContextKey).(string)
			if serverName == "" {
				return nil, fmt.Errorf("missing SNI in context")
			}

			d := &net.Dialer{Timeout: 5 * time.Second, KeepAlive: 30 * time.Second}
			raw, err := d.DialContext(ctx, network, addr)
			if err != nil {
				return nil, err
			}

			tlsConn := tls.Client(raw, &tls.Config{ServerName: serverName, MinVersion: tls.VersionTLS12})
			if err := tlsConn.HandshakeContext(ctx); err != nil {
				_ = raw.Close()
				return nil, err
			}
			return tlsConn, nil
		},
		MaxIdleConns:        2048,
		MaxIdleConnsPerHost: 512,
		IdleConnTimeout:     90 * time.Second,
		TLSHandshakeTimeout: 5 * time.Second,
		ForceAttemptHTTP2:   true,
	}

	return &Engine{manager: manager, transport: &transportWithSNI{base: base}}
}

type transportWithSNI struct{ base *http.Transport }

func (t *transportWithSNI) RoundTrip(req *http.Request) (*http.Response, error) {
	cloned := req.Clone(context.WithValue(req.Context(), sniContextKey, normalizeHost(req.Host)))
	return t.base.RoundTrip(cloned)
}

func (e *Engine) Handler() http.Handler {
	rp := &httputil.ReverseProxy{
		Director: func(req *http.Request) {
			host := normalizeHost(req.Host)
			cfIP := e.nextCFIP()

			e.upTraffic.Add(estimateRequestBytes(req))

			req.URL = &url.URL{Scheme: "https", Host: net.JoinHostPort(cfIP, "443"), Path: req.URL.Path, RawPath: req.URL.RawPath, RawQuery: req.URL.RawQuery}
			req.Host = host
			req.Header.Set("Host", host)
			req.Header.Set("X-Forwarded-Host", host)
			clientIP := clientIP(req.RemoteAddr)
			req.Header.Set("X-Real-IP", clientIP)
			req.Header.Set("X-Forwarded-For", clientIP)
			if req.TLS != nil {
				req.Header.Set("X-Forwarded-Proto", "https")
			} else {
				req.Header.Set("X-Forwarded-Proto", "http")
			}
			log.Printf("[Proxy] host=%s cf_ip=%s remote=%s", host, cfIP, req.RemoteAddr)
		},
		Transport: e.transport,
		ErrorHandler: func(w http.ResponseWriter, r *http.Request, err error) {
			log.Printf("[Error] host=%s remote=%s err=%v", r.Host, r.RemoteAddr, err)
			http.Error(w, "Bad Gateway", http.StatusBadGateway)
		},
	}

	return http.HandlerFunc(func(w http.ResponseWriter, r *http.Request) {
		host := normalizeHost(r.Host)
		if !e.isAllowed(host) {
			log.Printf("[Block] host=%s remote=%s", host, r.RemoteAddr)
			w.WriteHeader(http.StatusForbidden)
			_, _ = w.Write([]byte("Access Denied"))
			return
		}
		cw := &countingResponseWriter{ResponseWriter: w, down: &e.downTraffic}
		rp.ServeHTTP(cw, r)
	})
}

func (e *Engine) ConsumeTraffic() (up uint64, down uint64) {
	up = e.upTraffic.Swap(0)
	down = e.downTraffic.Swap(0)
	return
}

func (e *Engine) StartCFHealthCheck(ctx context.Context, interval time.Duration) {
	if interval <= 0 {
		interval = 5 * time.Second
	}
	go func() {
		ticker := time.NewTicker(interval)
		defer ticker.Stop()

		for {
			select {
			case <-ctx.Done():
				return
			case <-ticker.C:
				snap := e.manager.Snapshot()
				if len(snap.CFIPs) == 0 {
					continue
				}
				live := checkHealthyCFIPs(snap.CFIPs)
				if len(live) == 0 {
					live = snap.CFIPs
				}
				e.healthMu.Lock()
				e.healthCFIPs = live
				e.healthMu.Unlock()
			}
		}
	}()
}

func checkHealthyCFIPs(ips []string) []string {
	var wg sync.WaitGroup
	live := make([]string, 0, len(ips))
	var mu sync.Mutex

	for _, ip := range ips {
		ip := strings.TrimSpace(ip)
		if ip == "" {
			continue
		}
		wg.Add(1)
		go func(target string) {
			defer wg.Done()
			conn, err := net.DialTimeout("tcp", net.JoinHostPort(target, "443"), time.Second)
			if err != nil {
				return
			}
			_ = conn.Close()
			mu.Lock()
			live = append(live, target)
			mu.Unlock()
		}(ip)
	}
	wg.Wait()
	return live
}

func (e *Engine) isAllowed(host string) bool {
	s := e.manager.Snapshot()
	_, ok := s.Whitelist[host]
	return ok
}

func (e *Engine) nextCFIP() string {
	e.healthMu.RLock()
	healthy := append([]string(nil), e.healthCFIPs...)
	e.healthMu.RUnlock()
	if len(healthy) == 0 {
		s := e.manager.Snapshot()
		healthy = s.CFIPs
	}
	if len(healthy) == 0 {
		return "1.1.1.1"
	}
	idx := e.rr.Add(1)
	return healthy[idx%uint64(len(healthy))]
}

func normalizeHost(raw string) string {
	host, _, err := net.SplitHostPort(raw)
	if err == nil {
		return strings.ToLower(host)
	}
	return strings.ToLower(raw)
}

func clientIP(remoteAddr string) string {
	host, _, err := net.SplitHostPort(remoteAddr)
	if err != nil {
		return remoteAddr
	}
	return host
}

func estimateRequestBytes(req *http.Request) uint64 {
	sz := uint64(len(req.Method) + len(req.Proto) + len(req.URL.Path) + len(req.URL.RawQuery))
	for k, vals := range req.Header {
		sz += uint64(len(k))
		for _, v := range vals {
			sz += uint64(len(v))
		}
	}
	if req.ContentLength > 0 {
		sz += uint64(req.ContentLength)
	}
	return sz
}

func HTTPAddr(port int) string {
	return fmt.Sprintf(":%d", port)
}
