package proxy

import (
	"crypto/tls"
	"net"
	"net/http"
	"net/http/httputil"
	"net/url"
	"strings"
	"time"
)

// NewReverseProxy 创建一个反向代理
func NewReverseProxy(target string, host string) (*httputil.ReverseProxy, error) {
	targetURL, err := url.Parse(target)
	if err != nil {
		return nil, err
	}

	proxy := httputil.NewSingleHostReverseProxy(targetURL)

	// 自定义 Director 修改请求
	originalDirector := proxy.Director
	proxy.Director = func(req *http.Request) {
		originalDirector(req)
		
		// 1. 强制设置 Host 头 (这是回源的关键，Cloudflare 依靠这个识别域名)
		req.Host = host
		req.URL.Host = targetURL.Host
		req.URL.Scheme = targetURL.Scheme

		// 2. [核心修复] 透传真实客户端 IP
		// 获取客户端 IP (去除端口号)
		clientIP, _, err := net.SplitHostPort(req.RemoteAddr)
		if err == nil {
			// 设置 X-Real-IP (很多源站 Nginx 依赖这个)
			req.Header.Set("X-Real-IP", clientIP)

			// 设置 X-Forwarded-For (追加模式)
			// 格式: ClientIP, Proxy1, Proxy2...
			prior := req.Header.Get("X-Forwarded-For")
			if prior != "" {
				req.Header.Set("X-Forwarded-For", prior+", "+clientIP)
			} else {
				req.Header.Set("X-Forwarded-For", clientIP)
			}
		}

		// 3. 伪装 User-Agent (可选，防止被某些简单的反爬策略拦截)
		if req.Header.Get("User-Agent") == "" {
			req.Header.Set("User-Agent", "Mozilla/5.0 (Compatible; CF-Proxy/1.0)")
		}
	}

	// 自定义 Transport 处理 SSL/TLS
	proxy.Transport = &http.Transport{
		Proxy: http.ProxyFromEnvironment,
		DialContext: (&net.Dialer{
			Timeout:   30 * time.Second,
			KeepAlive: 30 * time.Second,
		}).DialContext,
		ForceAttemptHTTP2:     true,
		MaxIdleConns:          100,
		IdleConnTimeout:       90 * time.Second,
		TLSHandshakeTimeout:   10 * time.Second,
		ExpectContinueTimeout: 1 * time.Second,
		TLSClientConfig: &tls.Config{
			// 关键：Cloudflare 的 IP 证书是通用的，必须跳过主机名校验或设为 ServerName
			InsecureSkipVerify: true, 
			ServerName:         host, 
		},
	}

	// 错误处理
	proxy.ErrorHandler = func(w http.ResponseWriter, r *http.Request, err error) {
		// Log error here if needed
		w.WriteHeader(http.StatusBadGateway)
		w.Write([]byte("502 Bad Gateway (Edge Proxy Error)"))
	}

	return proxy, nil
}
