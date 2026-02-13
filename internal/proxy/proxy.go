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

// 统计流量的 Writer
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
}

func New(manager *config.Manager) *Engine {
	// 自定义 Transport 以支持动态 SNI (多域名支持)
	base := &http.Transport{
		Proxy: http.ProxyFromEnvironment,
		DialContext: (&net.Dialer{
			Timeout:   5 * time.Second,
			KeepAlive: 30 * time.Second,
		}).DialContext,
		DialTLSContext: func(ctx context.Context, network, addr string) (net.Conn, error) {
			// 从 Context 获取当前请求的域名
			serverName, _ := ctx.Value(sniContextKey).(string)
			if serverName == "" {
				return nil, fmt.Errorf("missing SNI in context")
			}

			d := &net.Dialer{Timeout: 5 * time.Second, KeepAlive: 30 * time.Second}
			raw, err := d.DialContext(ctx, network, addr)
			if err != nil {
				return nil, err
			}

			// 关键：针对当前域名进行握手，跳过 IP 证书校验
			tlsConn := tls.Client(raw, &tls.Config{
				ServerName:         serverName,
				MinVersion:         tls.VersionTLS12,
				InsecureSkipVerify: true, // CF 边缘 IP 证书通用，需跳过校验
			})
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
	// 将 Host 注入 Context 供 DialTLSContext 使用
	cloned := req.Clone(context.WithValue(req.Context(), sniContextKey, normalizeHost(req.Host)))
	return t.base.RoundTrip(cloned)
}

func (e *Engine) Handler() http.Handler {
	rp := &httputil.ReverseProxy{
		Director: func(req *http.Request) {
			host := normalizeHost(req.Host)
			cfIP := e.nextCFIP()

			// 统计上行流量
			e.upTraffic.Add(estimateRequestBytes(req))

			// 设置后端目标 (HTTPS -> Cloudflare IP)
			req.URL = &url.URL{
				Scheme: "https", 
				Host: net.JoinHostPort(cfIP, "443"), 
				Path: req.URL.Path, 
				RawPath: req.URL.RawPath, 
				RawQuery: req.URL.RawQuery,
			}
			
			// 1. 强制 Host 头 (回源关键)
			req.Host = host
			req.Header.Set("Host", host)
			req.Header.Set("X-Forwarded-Host", host)

			// 2. [核心修复] 透传真实客户端 IP
			// 即使没有 User-Agent，IP 也要透传，否则源站无法风控
			clientIP := clientIP(req.RemoteAddr)
			req.Header.Set("X-Real-IP", clientIP)
			
			// 追加模式设置 X-Forwarded-For
			prior := req.Header.Get("X-Forwarded-For")
			if prior != "" {
				req.Header.Set("X-Forwarded-For", prior+", "+clientIP)
			} else {
				req.Header.Set("X-Forwarded-For", clientIP)
			}

			// 3. 原封不动转发 User-Agent (已移除伪装逻辑)
			// Go 的 ReverseProxy 默认会保留客户端原始 Header
			// 如果客户端没发 UA，这里也不会发，完全透明。

			// 4. 设置协议头
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
		// 包装 Writer 以统计下行流量
		cw := &countingResponseWriter{ResponseWriter: w, down: &e.downTraffic}
		rp.ServeHTTP(cw, r)
	})
}

// 获取并重置流量计数
func (e *Engine) ConsumeTraffic() (up uint64, down uint64) {
	up = e.upTraffic.Swap(0)
	down = e.downTraffic.Swap(0)
	return
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
