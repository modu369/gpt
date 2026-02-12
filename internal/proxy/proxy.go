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
	"sync/atomic"
	"time"

	"github.com/example/cf-proxy/internal/config"
)

type contextKey string

const sniContextKey contextKey = "upstream_sni"

type Engine struct {
	manager   *config.Manager
	rr        atomic.Uint64
	transport http.RoundTripper
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
		rp.ServeHTTP(w, r)
	})
}

func (e *Engine) isAllowed(host string) bool {
	s := e.manager.Snapshot()
	_, ok := s.Whitelist[host]
	return ok
}

func (e *Engine) nextCFIP() string {
	s := e.manager.Snapshot()
	if len(s.CFIPs) == 0 {
		return "1.1.1.1"
	}
	idx := e.rr.Add(1)
	return s.CFIPs[idx%uint64(len(s.CFIPs))]
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

func HTTPAddr(port int) string {
	return fmt.Sprintf(":%d", port)
}
