/** -- netconfdriver.cpp --
 *
 * Establish a NETCONF session to a host via SSH, then send and receive XML data
 * as directed, returning all received data. Data is received from and returned
 * to the caller on either stdin/stdout or a TCP/IP channel.
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

struct RunData {
	ExecMethod method;
	int sock_in;
	int sock_out;
	bool ssh2_init;
	LIBSSH2_SESSION* ssh_session;
	LIBSSH2_CHANNEL* ssh_channel;
	std::list< std::string > in_blocks;

	RunData() :
	sock_in(0),
	sock_out(0),
	ssh2_init(false),
	ssh_session(0),
	ssh_channel(0)
	{}
};

std::string fmt(const char* msg, ...) {
	static char buf[8192];
	va_list args;
	va_start(args, msg);
	vsnprintf(buf, 8192, msg, args);
	va_end(args);
	buf[8191] = '\0';
	return buf;
}

std::string GetInput(RunData& rdata) {
	if (rdata.in_blocks.empty())
		throw fmt("No more lines");
	std::string block = rdata.in_blocks.front();
	rdata.in_blocks.pop_front();
	return block;
}
void SendOutput(const RunData& rdata, const std::string& output) {
	if (rdata.method == METHOD_TCP) {
		send(rdata.sock_in, "*", 1, 0);
		send(rdata.sock_in, output.c_str(), output.length(), 0);
		send(rdata.sock_in, "]]>]]>", 6, 0);
	} else //METHOD_STDIO
		fprintf(stdout, "*%s]]>]]>\n", output.c_str());
}
void SendOutputInfo(const RunData& rdata, const std::string& output) {
	if (rdata.method == METHOD_TCP) {
		send(rdata.sock_in, "+", 1, 0);
		send(rdata.sock_in, output.c_str(), output.length(), 0);
		send(rdata.sock_in, "]]>]]>", 6, 0);
	} else //METHOD_STDIO
		fprintf(stdout, "+%s]]>]]>\n", output.c_str());
}
void SendOutputError(const RunData& rdata, const std::string& error) {
	if (rdata.method == METHOD_TCP) {
		send(rdata.sock_in, "-", 1, 0);
		send(rdata.sock_in, error.c_str(), error.length(), 0);
		send(rdata.sock_in, "]]>]]>", 6, 0);
	} else //METHOD_STDIO
		fprintf(stdout, "-%s]]>]]>\n", error.c_str());
}

char GetTermChar(const RunData& rdata) {
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
}
std::string GetTermDocument(const RunData& rdata) {
	static std::string buf;
	buf.clear();
	while (true) {
		buf += GetTermChar(rdata);
		size_t len = buf.length();
		if (len >= 6
		&& buf[len - 6] == ']'
		&& buf[len - 5] == ']'
		&& buf[len - 4] == '>'
		&& buf[len - 3] == ']'
		&& buf[len - 2] == ']'
		&& buf[len - 1] == '>') {
			if (len == 6)
				throw fmt("Empty document");
			break;
		}
	}
	return buf;
}
void SendTerm(const RunData& rdata, const std::string& snd) {
	libssh2_channel_write(rdata.ssh_channel, snd.c_str(), snd.length());
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

		char buf[8192];
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
					if ((buf_filled >= 6
					&& buf[buf_filled - 6] == ']'
					&& buf[buf_filled - 5] == ']'
					&& buf[buf_filled - 4] == '>'
					&& buf[buf_filled - 3] == ']'
					&& buf[buf_filled - 2] == ']'
					&& buf[buf_filled - 1] == '>'
					&& buf[buf_filled] == '\n') || buf_filled == 8191) {
						if (buf_filled == 6)
							throw fmt("Empty block");
						if (buf_filled < 8191)
							buf[buf_filled - 6] = '\0';
						else
							buf[buf_filled] = '\0';
						break;
					}
					++buf_filled;
				}
			}
			if (buf_filled <= 0)
				break;
			rdata.in_blocks.push_back(buf);
		}

		std::string host_ip = GetInput(rdata);
		std::string auth_method = GetInput(rdata);

		int rc = libssh2_init(0);
		if (rc != 0)
			throw fmt("Failed to initialize libssh2: %d", rc);
		rdata.ssh2_init = true;

		int sock = socket(AF_INET, SOCK_STREAM, 0);
		struct sockaddr_in sin;
		sin.sin_family = AF_INET;
		sin.sin_port = htons(830);
		sin.sin_addr.s_addr = inet_addr(host_ip.c_str());
		if (connect(sock, (struct sockaddr*)(&sin), sizeof(struct sockaddr_in)) != 0)
			throw fmt("Failed to connect to %s on port 22", host_ip.c_str());
		rdata.sock_out = sock;

		LIBSSH2_SESSION* session = libssh2_session_init();
		if (libssh2_session_startup(session, sock))
			throw fmt("Failed to establish SSH session");
		rdata.ssh_session = session;
		libssh2_keepalive_config(session, 1, 1);

		if (auth_method == "userpass") {
			std::string uname = GetInput(rdata);
			std::string pass = GetInput(rdata);
			if (libssh2_userauth_password(session, uname.c_str(), pass.c_str()) != 0)
				throw fmt("Authentication by password failed");
		} else if (auth_method == "rsa") {
			std::string uname = GetInput(rdata);
			std::string pubkeyfile = GetInput(rdata);
			std::string privkeyfile = GetInput(rdata);
			if (libssh2_userauth_publickey_fromfile(session, uname.c_str(), pubkeyfile.c_str(), privkeyfile.c_str(), "")  != 0)
				throw fmt("Authentication by RSA key failed");
		}

		if (!(rdata.ssh_channel = libssh2_channel_open_session(session)))
			throw fmt("Unable to open a channel");
		if (libssh2_channel_subsystem(rdata.ssh_channel, "netconf") != 0)
			throw fmt("Failed requesting NETCONF on channel");

		std::string cmd;
		while (true) {
			try {
				cmd = GetInput(rdata);
			} catch (const std::string&) {
				break;
			}
			SendTerm(rdata, cmd + "]]>]]>");
			SendOutputInfo(rdata, GetTermDocument(rdata));
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
