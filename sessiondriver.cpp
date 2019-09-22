/** -- sessiondriver.cpp --
 *
 * Establish a telnet or SSH session to a host, then execute commands as
 * directed and return all output. Commands are received and data is
 * returned on either stdin/stdout or a TCP/IP channel.
 */


#include <cerrno>
#include <cctype>
#include <cstdarg>
#include <cstdio>
#include <string>
#include <list>
#include <queue>
#include <pcrecpp.h>
#include <libtelnet.h>
extern "C" {
#include <fcntl.h>
#ifdef WIN32
#include <windows.h>
#include <winsock2.h>
#else
#include <sys/socket.h>
#include <netinet/in.h>
#include <unistd.h>
#include <arpa/inet.h>
#endif
#include <sys/types.h>
#include <libssh2.h>
}

const int NETWK_TIMEOUT_SECONDS = 15;

static const telnet_telopt_t my_telopts[] = {
	{TELNET_TELOPT_ECHO, TELNET_WONT, TELNET_DO},
    {-1, 0, 0}
};

enum ExecMethod {
	METHOD_TCP = 0,
	METHOD_STDIO
};
enum DataMode {
	DATA_CLI = 0,
	DATA_NETCONF
};
enum SessionProtocol {
	PROTO_SSH = 0,
	PROTO_TELNET
};

struct RunData {
	ExecMethod method;
	int sock_host;
	int sock_driver;
	pcrecpp::RE prompt_regex;
	pcrecpp::RE cont_regex;
	DataMode dmode;
	SessionProtocol proto;
	bool ssh2_init;
	LIBSSH2_SESSION* ssh_session;
	LIBSSH2_CHANNEL* ssh_channel;
	telnet_t* tel;
	std::queue< char > telbuffer;
	std::list< std::string > driver_lines;

	RunData() :
	sock_host(0),
	sock_driver(0),
	prompt_regex("[^>]+>"),
	cont_regex("--MORE--"),
	ssh2_init(false),
	ssh_session(0),
	ssh_channel(0)
	{}
} rdata;


std::string fmt(const char* msg, ...) {
	static char buf[1024];
	va_list args;
	va_start(args, msg);
	vsnprintf(buf, 1024, msg, args);
	va_end(args);
	buf[1023] = '\0';
	return buf;
}

std::string GetFromDriver() {
	static std::string buf;
	static char ch;
	static size_t blen;
	while (true) {
		buf.clear();
		while (true) {
			if (rdata.method == METHOD_TCP
			&& recv(rdata.sock_driver, &ch, 1, 0) <= 0) {
#ifdef WIN32
				if (WSAGetLastError() != WSAEWOULDBLOCK)
#else
				if (errno != EAGAIN && errno != EWOULDBLOCK)
#endif
					throw fmt("EOF or error on driver TCP input");
				struct timeval timeout;
				timeout.tv_sec = NETWK_TIMEOUT_SECONDS;
				timeout.tv_usec = 0;
				fd_set fd;
				FD_ZERO(&fd);
				FD_SET(rdata.sock_driver, &fd);
				if (select(rdata.sock_driver + 1, &fd, 0, 0, &timeout) <= 0)
					throw fmt("Select timeout");
			} else if (rdata.method == METHOD_STDIO && fread(&ch, 1, 1, stdin) != 1)
				throw fmt("EOF or error on driver STDIO input");
			else {
				blen = buf.length();
				if (rdata.dmode == DATA_CLI && ch == '\n') {
					if (blen == 0)
						throw fmt("Empty driver input");
					break;
				} else if (rdata.dmode == DATA_NETCONF
				&& blen >= 6
				&& buf[blen - 6] == ']'
				&& buf[blen - 5] == ']'
				&& buf[blen - 4] == '>'
				&& buf[blen - 3] == ']'
				&& buf[blen - 2] == ']'
				&& buf[blen - 1] == '>'
				&& ch == '\n') {
					if (blen == 6)
						throw fmt("Empty driver input");
					buf.erase(blen - 6);
					break;
				}
				buf += ch;
			}
		}
		if (buf.substr(0, 4) == ":end")
			throw fmt("End of driver input");
		else if (buf.substr(0, 7) == "prompt:")
			rdata.prompt_regex = pcrecpp::RE(buf.substr(7));
		else if (buf.substr(0, 13) == "continuation:")
			rdata.cont_regex = pcrecpp::RE(buf.substr(13));
		else
			return buf;
	}
}
void SendToDriver(const std::string& output) {
	if (rdata.method == METHOD_TCP) {
		send(rdata.sock_driver, output.c_str(), output.length(), 0);
		if (rdata.dmode == DATA_CLI)
			send(rdata.sock_driver, "\n", 1, 0);
		else
			send(rdata.sock_driver, "]]>]]>\n", 7, 0);
	} else {
		fputs(output.c_str(), stdout);
		if (rdata.dmode == DATA_CLI)
			fputs("\n", stdout);
		else
			fputs("]]>]]>\n", stdout);
		fflush(stdout);
	}
}

char GetCharFromHost() {
	if (rdata.proto == PROTO_SSH) {
		libssh2_channel_set_blocking(rdata.ssh_channel, 0);
		int tmp;
		char c;
		while (true) {
			ssize_t ret = libssh2_channel_read(rdata.ssh_channel, &c, 1);
			if (ret == 1)
				return c;
			if (ret != LIBSSH2_ERROR_EAGAIN)
				throw fmt("No more chars to read (SSH)");
			libssh2_keepalive_send(rdata.ssh_session, &tmp);
			struct timeval timeout;
			timeout.tv_sec = NETWK_TIMEOUT_SECONDS;
			timeout.tv_usec = 0;
			fd_set fd;
			FD_ZERO(&fd);
			FD_SET(rdata.sock_host, &fd);
			fd_set *writefd = NULL;
			fd_set *readfd = NULL;
			int dir = libssh2_session_block_directions(rdata.ssh_session);
			if(dir & LIBSSH2_SESSION_BLOCK_INBOUND)
				readfd = &fd;
			if(dir & LIBSSH2_SESSION_BLOCK_OUTBOUND)
				writefd = &fd;
			int rc = select(rdata.sock_host + 1, readfd, writefd, NULL, &timeout);
			if (rc <= 0)
				throw fmt("Timeout or error waiting for data (SSH)");
		}
	} else { //PROTO_TELNET
		char c;
		while (rdata.telbuffer.size() <= 0) {
			int ret = recv(rdata.sock_host, &c, 1, 0);
			if (ret == 1) {
				telnet_recv(rdata.tel, &c, 1);
				continue;
			}
			if (ret != SOCKET_ERROR || WSAGetLastError() != WSAEWOULDBLOCK)
				throw fmt("No more chars to read (telnet)");
			struct timeval timeout;
			timeout.tv_sec = NETWK_TIMEOUT_SECONDS;
			timeout.tv_usec = 0;
			fd_set fd;
			FD_ZERO(&fd);
			FD_SET(rdata.sock_host, &fd);
			fd_set *writefd = NULL;
			fd_set *readfd = &fd;
			int rc = select(rdata.sock_host + 1, readfd, writefd, NULL, &timeout);
			if (rc <= 0)
				throw fmt("Timeout or error waiting for data (SSH)");
		}
		c = rdata.telbuffer.front();
		rdata.telbuffer.pop();
		return c;
	}
}
void SendToHost(const std::string& snd) {
	if (rdata.proto == PROTO_SSH)
		libssh2_channel_write(rdata.ssh_channel, snd.c_str(), snd.length());
	else //PROTO_TELNET
		telnet_send(rdata.tel, snd.c_str(), snd.length());
}


void my_telnet_event_handler(telnet_t* telnet, telnet_event_t* ev, void* ud) {
	switch (ev->type) {
		case TELNET_EV_DATA:
			for (size_t i = 0; i < ev->data.size; ++i)
				rdata.telbuffer.push(ev->data.buffer[i]);
			break;
		case TELNET_EV_SEND:
			send(rdata.sock_host, ev->data.buffer, ev->data.size, 0);
			break;
		case TELNET_EV_ERROR:
			throw fmt("TELNET error: %s", ev->error.msg);
			break;
		default:
			break;
	}
}


int main(int argc, char* argv[]) {
#ifdef WIN32
	WSADATA wsadata;
	WSAStartup(MAKEWORD(2,0), &wsadata);
#endif

	bool exec_ok = true;
	if (argc < 3) {
		fprintf(stderr, "Must provide data mode and execution method on command line\n");
		return 1;
	}

	rdata.dmode = DATA_CLI;
	if (strcmp(argv[1], "netconf") == 0)
		rdata.dmode = DATA_NETCONF;
	else if (strcmp(argv[1], "cli") != 0) {
		fprintf(stderr, "Invalid data mode specified: '%s'\n", argv[1]);
		return 1;
	}

	rdata.method = METHOD_TCP;
	if (strcmp(argv[2], "stdio") == 0)
		rdata.method = METHOD_STDIO;
	else if (strcmp(argv[2], "tcp") != 0) {
		fprintf(stderr, "Invalid execution method specified: '%s'\n", argv[2]);
		return 1;
	}

	if (rdata.method == METHOD_TCP) {
		if (argc < 4) {
			fprintf(stderr, "Must provide TCP comm port on command line\n");
			return 1;
		}
		int comm_port = atoi(argv[3]);
		if (comm_port < 1025 || comm_port > 65535) {
			fprintf(stderr, "TCP comm port must be between 1025 and 65535\n");
			return 1;
		}
		rdata.sock_driver = socket(AF_INET, SOCK_STREAM, 0);
		struct sockaddr_in sin;
		sin.sin_family = AF_INET;
		sin.sin_port = htons(comm_port);
		sin.sin_addr.s_addr = inet_addr("127.0.0.1");
		if (connect(rdata.sock_driver, (struct sockaddr*)&sin, sizeof(struct sockaddr_in)) != 0) {
			fprintf(stderr, "Failed to connect to TCP comm port\n");
			return 1;
		}
	}

	try {
		SendToDriver("+Driver connection established");

		std::string p = GetFromDriver();
		rdata.proto = PROTO_SSH;
		if (p == "telnet")
			rdata.proto = PROTO_TELNET;
		else if (p != "ssh")
			throw fmt("Invalid protocol: '%s'", p.c_str());

		std::string host_ip = GetFromDriver();
		int host_port = atoi(GetFromDriver().c_str());
		if (host_port < 1 || host_port > 65535)
			throw fmt("Host connection port must be between 1 and 65535\n");

		int sock = socket(AF_INET, SOCK_STREAM, 0);
		struct sockaddr_in sin;
		sin.sin_family = AF_INET;
		sin.sin_port = htons(host_port);
		sin.sin_addr.s_addr = inet_addr(host_ip.c_str());
		if (connect(sock, (struct sockaddr*)(&sin), sizeof(struct sockaddr_in)) != 0)
			throw fmt("Failed to connect to %s on port %d", host_ip.c_str(), host_port);
		rdata.sock_host = sock;

		if (rdata.proto == PROTO_SSH) {
			int rc = libssh2_init(0);
			if (rc != 0)
				throw fmt("Failed to initialize libssh2: %d", rc);
			rdata.ssh2_init = true;

			LIBSSH2_SESSION* session = libssh2_session_init();
			if (libssh2_session_startup(session, sock))
				throw fmt("Failed to establish SSH session");
			rdata.ssh_session = session;
			libssh2_keepalive_config(session, 1, 1);

			std::string auth_method = GetFromDriver();
			if (auth_method == "userpass") {
				std::string uname = GetFromDriver();
				std::string pass = GetFromDriver();
				if (libssh2_userauth_password(session, uname.c_str(), pass.c_str()) != 0)
					throw fmt("Authentication by password failed");
			} else if (auth_method == "rsa") {
				std::string uname = GetFromDriver();
				std::string pubkeyfile = GetFromDriver();
				std::string privkeyfile = GetFromDriver();
				if (libssh2_userauth_publickey_fromfile(session, uname.c_str(), pubkeyfile.c_str(), privkeyfile.c_str(), "")  != 0)
					throw fmt("Authentication by RSA key failed");
			} else
				throw fmt("Invalid auth method: '%s'", auth_method.c_str());

			if (!(rdata.ssh_channel = libssh2_channel_open_session(session)))
				throw fmt("Unable to open a channel");

			if (rdata.dmode == DATA_CLI) {
				if (libssh2_channel_request_pty(rdata.ssh_channel, "vanilla") != 0)
					throw fmt("Failed requesting pty on channel");
				if (libssh2_channel_shell(rdata.ssh_channel) != 0)
					throw fmt("Unable to request shell on allocated pty");
			} else {
				if (libssh2_channel_subsystem(rdata.ssh_channel, "netconf") != 0)
					throw fmt("Failed requesting NETCONF on channel");
			}
		} else {
			rdata.tel = telnet_init(my_telopts, my_telnet_event_handler, 0, 0);
			if (!rdata.tel)
				throw fmt("Failed to allocate libtelnet handler");
		}

		if (rdata.dmode == DATA_CLI) {
			std::string buf;
			while (!rdata.prompt_regex.FullMatch(buf.c_str())) {
				char c = GetCharFromHost();
				if (c == '\r' || c == '\n')
					buf.clear();
				else
					buf += c;
			}
		} else {
			std::string hello = GetFromDriver();
			SendToHost(hello + "]]>]]>");
			std::string buf;
			size_t blen;
			while (true) {
				buf += GetCharFromHost();
				blen = buf.length();
				if (blen < 6)
					continue;
				if (buf[blen - 6] == ']'
				&& buf[blen - 5] == ']'
				&& buf[blen - 4] == '>'
				&& buf[blen - 3] == ']'
				&& buf[blen - 2] == ']'
				&& buf[blen - 1] == '>')
					break;
			}
			buf.erase(blen - 6);
			SendToDriver(std::string("*") + buf);
		}

		while (true) {
			std::string driver_input;
			try {
				driver_input = GetFromDriver();
			} catch (std::string&) {
				break;
			}
			if (rdata.dmode == DATA_CLI) {
				SendToHost(driver_input + "\r");
				while (GetCharFromHost() != '\n')
					;
				std::string buf;
				char c;
				while (true) {
					c = GetCharFromHost();
					if (c == 8) {
						if (buf.length() > 0)
							buf.erase(buf.length() - 1);
						continue;
					}
					if (c == 0) {
						buf.clear();
						continue;
					}
					if (c == '\n') {
						SendToDriver(std::string("*") + buf);
						buf.clear();
						continue;
					}
					buf += c;
					if (rdata.prompt_regex.FullMatch(buf.c_str()))
						break;
					if (rdata.cont_regex.FullMatch(buf.c_str()))
						SendToHost(" ");
				}
				SendToDriver("+Prompt");
			} else {
				SendToHost(driver_input + "]]>]]>");
				std::string buf;
				size_t blen;
				while (true) {
					buf += GetCharFromHost();
					blen = buf.length();
					if (blen >= 6
					&& buf[blen - 6] == ']'
					&& buf[blen - 5] == ']'
					&& buf[blen - 4] == '>'
					&& buf[blen - 3] == ']'
					&& buf[blen - 2] == ']'
					&& buf[blen - 1] == '>')
						break;
				}
				buf.erase(blen - 6);
				SendToDriver(std::string("*") + buf);
			}
		}

		SendToDriver("+Done");
	} catch (const std::string& emsg) {
		SendToDriver(std::string("-") + emsg);
		exec_ok = false;
	} catch (const std::exception& e) {
		SendToDriver(fmt("-Unhandled exception: %s", e.what()));
		exec_ok = false;
	} catch (...) {
		SendToDriver("Unhandled exception (unknown type)");
		exec_ok = false;
	}

	if (rdata.ssh_channel)
		libssh2_channel_free(rdata.ssh_channel);
	if (rdata.ssh_session) {
		libssh2_session_disconnect(rdata.ssh_session,
		"Normal Shutdown, Thank you for playing");
		libssh2_session_free(rdata.ssh_session);
	}
	if (rdata.ssh2_init)
		libssh2_exit();
#ifdef WIN32
	if (rdata.sock_host)
		closesocket(rdata.sock_host);
	if (rdata.sock_driver)
		closesocket(rdata.sock_driver);
#else
	if (rdata.sock_host)
		close(rdata.sock_host);
	if (rdata.sock_driver)
		close(rdata.sock_driver);
#endif

	return exec_ok ? 0 : 1;
}
