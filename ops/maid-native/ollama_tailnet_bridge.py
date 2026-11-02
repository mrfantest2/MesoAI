"""Loopback-only OpenAI API relay for Maid Native over Tailscale Serve.

Ollama rejects non-loopback Host headers forwarded by Tailscale Serve (403).
This strictly-scoped relay supplies the loopback Host without exposing
Ollama on an external network interface. No payloads or secrets are logged.
"""
import argparse
import http.client
import logging
from http.server import BaseHTTPRequestHandler, ThreadingHTTPServer
from urllib.parse import urlsplit

ALLOW = {
    "/v1/models": {"GET"},
    "/v1/chat/completions": {"POST"},
    "/v1/completions": {"POST"},
    "/v1/embeddings": {"POST"},
}
REQUEST_HEADERS = ("content-type", "accept", "authorization", "user-agent")
RESPONSE_HEADERS = ("content-type", "cache-control", "content-encoding", "x-request-id")
MAX_BODY = 8 * 1024 * 1024


class Handler(BaseHTTPRequestHandler):
    protocol_version = "HTTP/1.1"

    def do_GET(self):
        self.forward()

    def do_POST(self):
        self.forward()

    def forward(self):
        path = urlsplit(self.path).path
        if self.command not in ALLOW.get(path, set()):
            self.send_error(404, "Not available")
            return
        try:
            length = int(self.headers.get("Content-Length", "0"))
            if length < 0 or length > MAX_BODY or self.headers.get("Transfer-Encoding"):
                self.send_error(413, "Request body not supported")
                return
            body = self.rfile.read(length) if length else None
            conn = http.client.HTTPConnection("127.0.0.1", 11434, timeout=180)
            headers = {h: self.headers[h] for h in REQUEST_HEADERS if h in self.headers}
            headers["Host"] = "127.0.0.1:11434"
            headers["Connection"] = "close"
            conn.request(self.command, self.path, body=body, headers=headers)
            upstream = conn.getresponse()
            self.send_response_only(upstream.status, upstream.reason)
            for name in RESPONSE_HEADERS:
                value = upstream.getheader(name)
                if value is not None:
                    self.send_header(name, value)
            self.send_header("Transfer-Encoding", "chunked")
            self.send_header("Connection", "close")
            self.end_headers()
            while True:
                chunk = upstream.read1(16384)
                if not chunk:
                    break
                self.wfile.write(("%x\r\n" % len(chunk)).encode("ascii"))
                self.wfile.write(chunk + b"\r\n")
                self.wfile.flush()
            self.wfile.write(b"0\r\n\r\n")
            self.wfile.flush()
            conn.close()
        except (BrokenPipeError, ConnectionResetError):
            pass
        except (OSError, http.client.HTTPException) as e:
            logging.warning("Upstream failure: %s", type(e).__name__)
            if not self.headers_sent():
                try:
                    self.send_error(502, "Model server unavailable")
                except OSError:
                    pass
        finally:
            self.close_connection = True

    def headers_sent(self):
        return getattr(self, "_headers_buffer", None) is None and self.wfile.closed

    def log_message(self, fmt, *args):
        logging.info("%s %s", self.command, urlsplit(self.path).path)


def main():
    parser = argparse.ArgumentParser()
    parser.add_argument("--port", type=int, default=11436)
    args = parser.parse_args()
    logging.basicConfig(level=logging.INFO, format="%(asctime)s %(levelname)s %(message)s")
    httpd = ThreadingHTTPServer(("127.0.0.1", args.port), Handler)
    httpd.daemon_threads = True
    logging.info("Private relay listening on 127.0.0.1:%s", args.port)
    httpd.serve_forever()


if __name__ == "__main__":
    main()
