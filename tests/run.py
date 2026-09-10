"""HTTP integration checks against disposable PHP/Apache and MariaDB containers."""
import concurrent.futures
import http.client
import http.cookies
import os
from pathlib import Path
import re
import secrets
import shutil
import subprocess
import tempfile
import time
import urllib.parse

from package import FILES, ROOT

TOKEN = secrets.token_hex(4)
NETWORK = f"rovid-test-{TOKEN}"
DATABASE = f"{NETWORK}-db"
WEB = f"{NETWORK}-web"
IMAGE = "rovid-integration:local"
DB_PASSWORD = secrets.token_hex(20)
ADMIN_PASSWORD = secrets.token_hex(20)
CHECKS = 0


def docker(*args, input=None, quiet=False):
    result = subprocess.run(["docker", *args], input=input, text=True, capture_output=True)
    if result.returncode and not quiet:
        raise RuntimeError(f"Docker {args[0]} failed: {result.stderr[-3000:]}")
    return result.stdout.strip()


def check(condition, message):
    global CHECKS
    if not condition:
        raise AssertionError(message)
    CHECKS += 1


def sql(query):
    return docker("exec", "-i", DATABASE, "mariadb", "-uroot", f"-p{DB_PASSWORD}",
                  "--batch", "--skip-column-names", "rovid", input=query)


def wait_for(callback, timeout=60):
    deadline = time.monotonic() + timeout
    while time.monotonic() < deadline:
        try:
            if callback():
                return
        except (OSError, RuntimeError, http.client.HTTPException):
            pass
        time.sleep(0.5)
    raise TimeoutError("Test service did not become ready")


class Browser:
    def __init__(self, port):
        self.port = port
        self.cookies = {}

    def request(self, path="/", data=None, method=None):
        method = method or ("POST" if data is not None else "GET")
        headers = {"Cookie": "; ".join(f"{key}={value}" for key, value in self.cookies.items())}
        body = None
        if data is not None:
            body = urllib.parse.urlencode(data)
            headers["Content-Type"] = "application/x-www-form-urlencoded"
        connection = http.client.HTTPConnection("127.0.0.1", self.port, timeout=15)
        connection.request(method, path, body=body, headers=headers)
        response = connection.getresponse()
        status = response.status
        response_headers = dict((key.lower(), value) for key, value in response.getheaders())
        for key, value in response.getheaders():
            if key.lower() == "set-cookie":
                jar = http.cookies.SimpleCookie(value)
                self.cookies.update({key: item.value for key, item in jar.items()})
        content = response.read().decode("utf-8", "replace")
        connection.close()
        return status, response_headers, content


def csrf(content):
    match = re.search(r'name="csrf" value="([a-f0-9]+)"', content)
    check(match is not None, "CSRF field missing")
    return match.group(1)


def write_config(directory, base, query=False, password=ADMIN_PASSWORD):
    # Generated values contain no PHP string metacharacters.
    (directory / "config.php").write_text("<?php return " + f"""[
        'base_url' => '{base}',
        'db' => ['host' => 'database', 'port' => 3306, 'name' => 'rovid',
                 'user' => 'root', 'password' => '{DB_PASSWORD}'],
        'admin_password' => '{password}', 'default_length' => 5,
        'query_links' => {'true' if query else 'false'}
    ];""", encoding="utf-8")


def workflow(port, prefix, query=False):
    browser = Browser(port)
    status, headers, body = browser.request(prefix)
    check(status == 200 and "Kezelői jelszó" in body, "Login page unavailable")
    check("httponly" in headers.get("set-cookie", "").lower(), "Cookie is not HttpOnly")
    check("Létrehozott linkek" not in body, "Private list exposed before login")
    token = csrf(body)
    status, _, _ = browser.request(prefix, {"action": "create", "csrf": token,
                                          "url": "https://example.org/private", "length": 5})
    check(status == 401, "Anonymous creation accepted")
    status, _, _ = browser.request(prefix, {"action": "login", "password": ADMIN_PASSWORD})
    check(status == 403, "Login accepted without CSRF")
    status, _, _ = browser.request(prefix, {"action": "login", "csrf": token, "password": "wrong"})
    check(status == 401, "Incorrect password accepted")
    old_cookies = browser.cookies.copy()
    status, headers, _ = browser.request(prefix, {"action": "login", "csrf": token, "password": ADMIN_PASSWORD})
    check(status == 303, "Login failed")
    check(browser.cookies != old_cookies, "Session ID did not rotate")
    status, _, body = browser.request(prefix)
    check(status == 200 and 'value="5"' in body and "Új rövid link" in body, "Default length is not 5")
    token = csrf(body)
    count = int(sql("SELECT COUNT(*) FROM short_links;"))
    invalid = [
        {"url": "javascript:alert(1)", "length": "5"},
        {"url": "ftp://example.org/file", "length": "5"},
        {"url": "https://user:password@example.org", "length": "5"},
        {"url": "https://example.org/\r\nX-Evil:yes", "length": "5"},
        {"url": "https://example.org/" + "x" * 8192, "length": "5"},
        {"url": "https://example.org", "length": "2"},
        {"url": "https://example.org", "length": "33"},
        {"url": "https://example.org", "length": "5.5"},
        {"url": "https://example.org", "length": "5e0"},
        {"url[]": "https://example.org", "length": "5"},
        {"url": "https://example.org", "length[]": "5"},
    ]
    for values in invalid:
        status, _, _ = browser.request(prefix, {"action": "create", "csrf": token, **values})
        check(status == 422, f"Invalid input was accepted: {str(values)[:120]}")
    status, _, _ = browser.request(prefix, {"action": "create", "csrf": "bad",
                                          "url": "https://example.org", "length": "5"})
    check(status == 403, "Creation accepted invalid CSRF")
    check(int(sql("SELECT COUNT(*) FROM short_links;")) == count, "Invalid input created a record")
    for length in [3, 5, 12, 32]:
        destination = f"https://example.org/some/long/path?length={length}&quoted=%22test%22#section"
        status, headers, _ = browser.request(prefix, {"action": "create", "csrf": token,
                                                    "url": destination, "length": str(length)})
        check(status == 303 and headers.get("location", "").endswith(prefix), "Create/redirect failed")
        code, stored = sql("SELECT code, destination FROM short_links ORDER BY id DESC LIMIT 1;").split("\t")
        check(len(code) == length and re.fullmatch(r"[A-Za-z0-9]+", code), "Incorrect generated length")
        check(stored == destination, "Destination was altered")
        _, _, body = browser.request(prefix)
        suffix = ("index.php?code=" if query else "") + code
        check(suffix in body and "Elkészült a rövid linked" in body, "Result missing")
        _, _, again = browser.request(prefix)
        check("Elkészült a rövid linked" not in again, "Success message was not one-time")
        anonymous = Browser(port)
        status, headers, _ = anonymous.request(prefix + suffix, method="HEAD")
        check(status == 302 and headers.get("location") == destination, "HEAD redirect failed")
        check(sql(f"SELECT clicks FROM short_links WHERE code='{code}';") == "0", "HEAD counted as click")
        status, headers, _ = anonymous.request(prefix + suffix)
        check(status == 302 and headers.get("location") == destination, "Public redirect failed")
        check(not anonymous.cookies, "Redirect created an unnecessary session")
        check(sql(f"SELECT clicks FROM short_links WHERE code='{code}';") == "1", "Click not recorded")
        if not query:
            status, headers, _ = anonymous.request(prefix + code + "?code=missing&utm_source=test")
            check(status == 302 and headers.get("location") == destination, "Query overwrote rewritten code")
        status, _, _ = anonymous.request(prefix + suffix, {"unused": "1"})
        check(status == 405, "POST short link was accepted")

    status, _, _ = browser.request(prefix, {"action": "logout", "csrf": token})
    check(status == 303, "Logout failed")
    _, _, body = browser.request(prefix)
    check("Kezelői jelszó" in body and "Létrehozott linkek" not in body, "Logout did not revoke access")
    print(f"PASS: {'query fallback' if query else 'pretty URLs'} at {prefix}", flush=True)


def run():
    print("Building PHP 8.3 / Apache test image…", flush=True)
    docker("build", "-q", "-t", IMAGE, "-f", str(ROOT / "tests/Dockerfile"), str(ROOT))
    with tempfile.TemporaryDirectory(prefix="rovid-test-") as temp:
        root = Path(temp)
        os.chmod(root, 0o755)
        webroot = root / "www"
        webroot.mkdir()
        for relative in ["", "rovid", "query", "setup"]:
            target = webroot / relative
            target.mkdir(exist_ok=True)
            for filename in FILES:
                (target / filename).parent.mkdir(parents=True, exist_ok=True)
                shutil.copy2(ROOT / filename, target / filename)
        try:
            docker("network", "create", NETWORK)
            docker("run", "-d", "--name", DATABASE, "--network", NETWORK,
                   "--network-alias", "database", "--tmpfs", "/var/lib/mysql",
                   "-e", f"MARIADB_ROOT_PASSWORD={DB_PASSWORD}", "-e", "MARIADB_DATABASE=rovid",
                   "mariadb:11.4")
            wait_for(lambda: sql("SELECT 1;") == "1")
            sql((ROOT / "schema.sql").read_text())
            sql((ROOT / "schema.sql").read_text())
            print("PASS: schema import and repeated import", flush=True)
            docker("run", "-d", "--name", WEB, "--network", NETWORK,
                   "-p", "127.0.0.1::80", "--mount", f"type=bind,source={webroot},target=/var/www/html,readonly",
                   IMAGE)
            port = int(docker("port", WEB, "80/tcp").rsplit(":", 1)[1])
            base = f"http://127.0.0.1:{port}"
            for relative, query in [("", False), ("rovid", False), ("query", True)]:
                write_config(webroot / relative, base + (f"/{relative}" if relative else ""), query)
            browser = Browser(port)
            wait_for(lambda: browser.request()[0] == 200)
            workflow(port, "/")
            workflow(port, "/rovid/")
            workflow(port, "/query/", query=True)

            status, _, body = browser.request("/setup/")
            check(status == 503 and "Már csak a beállítás" in body, "Missing config not handled")
            for blocked in ["/config.php", "/config.example.php", "/schema.sql", "/README.md",
                            "/app/functions.php", "/.git/config", "/rovid/config.php"]:
                status, _, body = browser.request(blocked)
                check(status == 403, f"Protected file not blocked: {blocked}")
                check(DB_PASSWORD not in body and ADMIN_PASSWORD not in body, "Secret exposed")
            for invalid in ["/NeverCreated12345", "/index.php?code[]=oops", "/index.php?code=a%0d%0a"]:
                check(browser.request(invalid)[0] == 404, f"Invalid code not rejected: {invalid}")
            for asset in ["app.css", "app.js", "favicon.svg"]:
                check(browser.request("/assets/" + asset)[0] == 200, f"Asset not served: {asset}")
            sql("INSERT INTO short_links (code, destination) VALUES ('CaseA', 'https://example.org/upper'), ('casea', 'https://example.org/lower');")
            for code, destination in [("CaseA", "upper"), ("casea", "lower")]:
                status, headers, _ = browser.request("/" + code)
                check(status == 302 and headers["location"] == f"https://example.org/{destination}", "Case-sensitive lookup failed")
            with concurrent.futures.ThreadPoolExecutor(max_workers=8) as executor:
                results = list(executor.map(lambda _: Browser(port).request("/CaseA")[0], range(20)))
            check(all(status == 302 for status in results), "Concurrent redirects failed")
            check(sql("SELECT clicks FROM short_links WHERE code='CaseA';") == "21", "Concurrent click updates lost")
            sql("INSERT INTO short_links (code, destination) VALUES ('Unsafe', 'javascript:alert(1)');")
            check(browser.request("/Unsafe")[0] == 404, "Unsafe stored target was redirected")

            sql("INSERT INTO short_links (code, destination) VALUES " + ",".join(
                f"('Page{n:03d}', 'https://example.org/{n}')" for n in range(25)) + ";")
            _, _, body = browser.request()
            browser.request("/", {"action": "login", "csrf": csrf(body), "password": ADMIN_PASSWORD})
            _, _, body = browser.request("/?page=2")
            check(body.count('class="link-row"') == 20 and "2 / " in body, "Pagination failed")
            write_config(webroot, base, password=secrets.token_hex(20))
            # The test image disables OPcache so config changes are visible immediately.
            _, _, body = browser.request()
            check("Kezelői jelszó" in body and "Létrehozott linkek" not in body, "Password change did not revoke session")
            token = csrf(body)
            for _ in range(5):
                check(browser.request("/", {"action": "login", "csrf": token, "password": "bad"})[0] == 401, "Rate limit triggered too early")
            check(browser.request("/", {"action": "login", "csrf": token, "password": "bad"})[0] == 429, "Login rate limit missing")
            docker("stop", DATABASE)
            status, _, body = browser.request("/CaseA")
            check(status == 503 and DB_PASSWORD not in body and "SQLSTATE" not in body, "Database failure leaked details")
            print(f"PASS: {CHECKS} assertions; redirects, access, CSRF, validation, subdirectory, query fallback, case sensitivity, concurrency, pagination, password rotation, throttling, errors.", flush=True)
        except Exception:
            print(docker("logs", "--tail", "12", WEB, quiet=True), flush=True)
            raise
        finally:
            docker("rm", "-f", WEB, DATABASE, quiet=True)
            docker("network", "rm", NETWORK, quiet=True)


if __name__ == "__main__":
    run()
