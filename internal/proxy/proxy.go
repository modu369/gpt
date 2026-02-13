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

// countingResponseWriter 用于捕获下行流量字节数
type countingResponseWriter struct {
	http.ResponseWriter
	down *atomic.Uint64
}

func (w *countingResponseWriter) Write(b []byte) (int, error) {
	n, err := w.ResponseWriter.Write(b)
	if n > 0 {
		w.down.Add(uint64(n)) // 累加下载流量
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

// New 创建代理引擎，保留原系统的 Transport 配置和动态 SNI 处理逻辑
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

			// 企业版穿透建议维持 TLS 1.2+ 握手
			tlsConn := tls.Client(raw, &tls.Config{
				ServerName:         serverName,
				MinVersion:         tls.VersionTLS12,
				InsecureSkipVerify: true, // 必须跳过以支持直连 CF 边缘 IP
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
	cloned := req.Clone(context.WithValue(req.Context(), sniContextKey, normalizeHost(req.Host)))
	return t.base.RoundTrip(cloned)
}

// Handler 处理请求，集成 True-Client-IP 穿透逻辑
func (e *Engine) Handler() http.Handler {
	rp := &httputil.ReverseProxy{
		Director: func(req *http.Request) {
			host := normalizeHost(req.Host)
			cfIP := e.nextCFIP()

			// 统计上行流量
			e.upTraffic.Add(estimateRequestBytes(req))

			// 设置转发目标为 Cloudflare 边缘节点
			req.URL = &url.URL{
				Scheme:   "https",
				Host:     net.JoinHostPort(cfIP, "443"),
				Path:     req.URL.Path,
				RawPath:  req.URL.RawPath,
				RawQuery: req.URL.RawQuery,
			}
			req.Host = host
			req.Header.Set("Host", host)
			req.Header.Set("X-Forwarded-Host", host)

			// 获取原始客户端 IP
			clientIP := clientIP(req.RemoteAddr)

			// ==========================================
			// [新增] Cloudflare Enterprise 企业版穿透核心头部
			// ==========================================
			req.Header.Set("True-Client-IP", clientIP) 
			
			// 维持标准的 Real-IP 和 Forwarded-For 透传
			req.Header.Set("X-Real-IP", clientIP)
			prior := req.Header.Get("X-Forwarded-For")
			if prior != "" {
				req.Header.Set("X-Forwarded-For", prior+", "+clientIP)
			} else {
				req.Header.Set("X-Forwarded-For", clientIP)
			}

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
		// 域名白名单验证
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

// ConsumeTraffic 原子交换并获取流量统计，供 main.go 的心跳使用
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
