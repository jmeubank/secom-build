/** -- termdriver.cpp --
 *
 * Establish a terminal session to a host via telnet or SSH, then execute
 * commands as directed and return all output. Commands are received and data is
 * returned on either stdin/stdout or a TCP/IP channel.
 */


#include <cerrno>
#include <cctype>
#include <cstdarg>
#include <cstdio>
#include <string>
#include <list>
#include <pcrecpp.h>
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


const int NETWK_TIMEOUT_SECONDS = 5;

enum ExecMethod {
	METHOD_TCP = 0,
	METHOD_STDIO
};
enum TermProtocol {
	PROTO_SSH = 0,
	PROTO_TELNET
};

struct RunData {
	ExecMethod method;
	int sock_in;
	int sock_out;
	pcrecpp::RE prompt_regex;
	pcrecpp::RE cont_regex;
	TermProtocol proto;
	bool ssh2_init;
	LIBSSH2_SESSION* ssh_session;
	LIBSSH2_CHANNEL* ssh_channel;
	std::list< std::string > in_lines;

	RunData() :
	sock_in(0),
	sock_out(0),
	prompt_regex("[^>]+>"),
	cont_regex("--MORE--"),
	ssh2_init(false),
	ssh_session(0),
	ssh_channel(0)
	{}
};


std::string fmt(const char* msg, ...) {
	static char buf[1024];
	va_list args;
	va_start(args, msg);
	vsnprintf(buf, 1024, msg, args);
	va_end(args);
	buf[1023] = '\0';
	return buf;
}

std::string GetInput(RunData& rdata) {
	while (true) {
		if (rdata.in_lines.empty())
			throw fmt("No more lines");
		std::string line = rdata.in_lines.front();
		rdata.in_lines.pop_front();
		if (line.substr(0, 7) == "prompt:")
			rdata.prompt_regex = pcrecpp::RE(line.substr(7));
		else if (line.substr(0, 13) == "continuation:")
			rdata.cont_regex = pcrecpp::RE(line.substr(13));
		else
			return line;
	}
}
void SendOutput(const RunData& rdata, const std::string& output) {
	if (rdata.method == METHOD_TCP) {
		send(rdata.sock_in, "*", 1, 0);
		send(rdata.sock_in, output.c_str(), output.length(), 0);
		send(rdata.sock_in, "\n", 1, 0);
	} else //METHOD_STDIO
		fprintf(stdout, "*%s\n", output.c_str());
}
void SendOutputInfo(const RunData& rdata, const std::string& output) {
	if (rdata.method == METHOD_TCP) {
		send(rdata.sock_in, "+", 1, 0);
		send(rdata.sock_in, output.c_str(), output.length(), 0);
		send(rdata.sock_in, "\n", 1, 0);
	} else //METHOD_STDIO
		fprintf(stdout, "+%s\n", output.c_str());
}
void SendOutputError(const RunData& rdata, const std::string& error) {
	if (rdata.method == METHOD_TCP) {
		send(rdata.sock_in, "-", 1, 0);
		send(rdata.sock_in, error.c_str(), error.length(), 0);
		send(rdata.sock_in, "\n", 1, 0);
	} else //METHOD_STDIO
		fprintf(stdout, "-%s\n", error.c_str());
}

char GetTermChar(const RunData& rdata) {
	if (rdata.proto == PROTO_SSH) {
		libssh2_channel_set_blocking(rdata.ssh_channel, 0);
		int tmp;
		while (true) {
			char c;
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
			FD_SET(rdata.sock_out, &fd);
			fd_set *writefd = NULL;
			fd_set *readfd = NULL;
			int dir = libssh2_session_block_directions(rdata.ssh_session);
			if(dir & LIBSSH2_SESSION_BLOCK_INBOUND)
				readfd = &fd;
			if(dir & LIBSSH2_SESSION_BLOCK_OUTBOUND)
				writefd = &fd;
			int rc = select(rdata.sock_out + 1, readfd, writefd, NULL, &timeout);
			if (rc <= 0)
				throw fmt("Timeout or error waiting for data (SSH)");
		}
	} else { //PROTO_TELNET
		throw fmt("Telnet not implemented yet - GetTermChar");
	}
}
void GetTermPrompt(const RunData& rdata) {
	char buf[1024];
	int buf_filled = 0;
	while (true) {
		buf[buf_filled] = GetTermChar(rdata);
		if (buf[buf_filled] == '\n' || buf_filled == 1022) {
			buf_filled = 0;
			continue;
		}
		buf[buf_filled + 1] = '\0';
		if (rdata.prompt_regex.FullMatch(buf))
			return;
		++buf_filled;
	}
}
void SendTerm(const RunData& rdata, const std::string& snd) {
	if (rdata.proto == PROTO_SSH)
		libssh2_channel_write(rdata.ssh_channel, snd.c_str(), snd.length());
	else { //PROTO_TELNET
		throw fmt("Telnet not implemented yet - SendTerm");
	}
}
void SendTermCommand(const RunData& rdata, const std::string& cmd) {
	SendTerm(rdata, cmd + "\r");
	while (GetTermChar(rdata) != '\n')
		;
}

int main(int argc, char* argv[]) {
#ifdef WIN32
		WSADATA wsadata;
		WSAStartup(MAKEWORD(2,0), &wsadata);
#endif

	RunData rdata;
	bool exec_ok = true;
	if (argc < 2) {
		fprintf(stderr, "Must provide execution method on command line\n");
		return 1;
	}

	rdata.method = METHOD_TCP;
	if (strcmp(argv[1], "stdio") == 0)
		rdata.method = METHOD_STDIO;
	else if (strcmp(argv[1], "tcp") != 0) {
		fprintf(stderr, "Invalid execution method specified: '%s'\n", argv[1]);
		return 1;
	}

	if (rdata.method == METHOD_TCP) {
		if (argc < 3) {
			fprintf(stderr, "Must provide TCP comm port on command line\n");
			return 1;
		}
		int comm_port = atoi(argv[2]);
		if (comm_port < 1025 || comm_port > 65535) {
			fprintf(stderr, "TCP comm port must be between 1025 and 65535\n");
			return 1;
		}
		rdata.sock_in = socket(AF_INET, SOCK_STREAM, 0);
		struct sockaddr_in sin;
		sin.sin_family = AF_INET;
		sin.sin_port = htons(comm_port);
		sin.sin_addr.s_addr = inet_addr("127.0.0.1");
		if (connect(rdata.sock_in, (struct sockaddr*)&sin, sizeof(struct sockaddr_in)) != 0) {
			fprintf(stderr, "Failed to connect to TCP comm port\n");
			return 1;
		}
	}

	try {
		SendOutputInfo(rdata, "Connection established");

		char buf[1024];
		while (true) {
			int buf_filled = 0;
			while (true) {
				if (rdata.method == METHOD_TCP
				&& recv(rdata.sock_in, buf + buf_filled, 1, 0) <= 0) {
#ifdef WIN32
					if (WSAGetLastError() != WSAEWOULDBLOCK)
#else
					if (errno != EAGAIN && errno != EWOULDBLOCK)
#endif
						break;
					struct timeval timeout;
					timeout.tv_sec = NETWK_TIMEOUT_SECONDS;
					timeout.tv_usec = 0;
					fd_set fd;
					FD_ZERO(&fd);
					FD_SET(rdata.sock_in, &fd);
					if (select(rdata.sock_in + 1, &fd, 0, 0, &timeout) <= 0)
						throw fmt("Select timeout");
				} else if (rdata.method == METHOD_STDIO
				&& fread(buf + buf_filled, 1, 1, stdin) != 1)
					break;
				else {
					if (buf[buf_filled] == '\n' || buf_filled == 1023) {
						if (buf_filled == 0)
							throw fmt("Empty line");
						buf[buf_filled] = '\0';
						break;
					}
					++buf_filled;
				}
			}
			if (buf_filled <= 0)
				break;
			std::string line(buf);
			if (line.substr(0, 4) == ":end")
				break;
			rdata.in_lines.push_back(line);
		}

		std::string p = GetInput(rdata);
		rdata.proto = PROTO_SSH;
		if (p == "telnet")
			rdata.proto = PROTO_TELNET;
		else if (p != "ssh")
			throw fmt("Invalid protocol: '%s'", p.c_str());

		std::string host_ip = GetInput(rdata);

		if (rdata.proto == PROTO_SSH) {
			std::string uname = GetInput(rdata);
			std::string pass = GetInput(rdata);

			int rc = libssh2_init(0);
			if (rc != 0)
				throw fmt("Failed to initialize libssh2: %d", rc);
			rdata.ssh2_init = true;

			int sock = socket(AF_INET, SOCK_STREAM, 0);
			struct sockaddr_in sin;
			sin.sin_family = AF_INET;
			sin.sin_port = htons(22);
			sin.sin_addr.s_addr = inet_addr(host_ip.c_str());
			if (connect(sock, (struct sockaddr*)(&sin), sizeof(struct sockaddr_in)) != 0)
				throw fmt("Failed to connect to %s on port 22", host_ip.c_str());
			rdata.sock_out = sock;

			LIBSSH2_SESSION* session = libssh2_session_init();
			if (libssh2_session_startup(session, sock))
				throw fmt("Failed to establish SSH session");
			rdata.ssh_session = session;
			libssh2_keepalive_config(session, 1, 1);

			if (libssh2_userauth_password(session, uname.c_str(), pass.c_str()) != 0)
				throw fmt("Username/password authentication failed");

			if (!(rdata.ssh_channel = libssh2_channel_open_session(session)))
				throw fmt("Unable to open a channel");
			if (libssh2_channel_request_pty(rdata.ssh_channel, "vanilla") != 0)
				throw fmt("Failed requesting pty on channel");
			if (libssh2_channel_shell(rdata.ssh_channel) != 0)
				throw fmt("Unable to request shell on allocated pty");
		}

		GetTermPrompt(rdata);
		while (true) {
			std::string cmd;
			try {
				cmd = GetInput(rdata);
			} catch (const std::string&) {
				break;
			}
			SendTermCommand(rdata, cmd);
			int buf_filled = 0;
			while (true) {
				char c = GetTermChar(rdata);
				if (c == 8) {
					if (buf_filled > 0)
						--buf_filled;
					continue;
				}
				buf[buf_filled] = c;
				++buf_filled;
				if (c == '\n' || buf_filled == 1024) {
					buf[buf_filled - 1] = '\0';
					SendOutput(rdata, buf);
					buf_filled = 0;
					continue;
				}
				buf[buf_filled] = '\0';
				if (rdata.prompt_regex.FullMatch(buf))
					break;
				if (rdata.cont_regex.FullMatch(buf))
					SendTerm(rdata, "\r");
			}
		}
	} catch (const std::string& emsg) {
		SendOutputError(rdata, emsg);
		exec_ok = false;
	} catch (const std::exception& e) {
		SendOutputError(rdata, fmt("Unhandled exception: %s", e.what()));
		exec_ok = false;
	} catch (...) {
		SendOutputError(rdata, fmt("Unhandled exception (unknown type)"));
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
	if (rdata.sock_out)
		closesocket(rdata.sock_out);
	if (rdata.sock_in)
		closesocket(rdata.sock_in);
#else
	if (rdata.sock_out)
		close(rdata.sock_out);
	if (rdata.sock_in)
		close(rdata.sock_in);
#endif

	return exec_ok ? 0 : 1;
}
