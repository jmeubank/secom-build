#include <libssh2.h>
#include <libssh2_sftp.h>
 
#ifdef WIN32
#include <windows.h>
#include <winsock2.h>
#endif
#ifdef HAVE_SYS_SOCKET_H
#include <sys/socket.h>
#endif
#ifdef HAVE_NETINET_IN_H
#include <netinet/in.h>
#endif
#ifdef HAVE_UNISTD_H
#include <unistd.h>
#endif
#ifdef HAVE_ARPA_INET_H
#include <arpa/inet.h>
#endif

#include <sys/types.h>
#include <fcntl.h>
#include <errno.h>
#include <stdio.h>
#include <ctype.h>
#include <stdarg.h>


struct my_ssh {
	int ssh2_init;
	int sock;
	int sock2;
	LIBSSH2_SESSION* session;
	LIBSSH2_CHANNEL* channel;
};


const int SSH_TIMEOUT = 2; //in seconds


void MySshShutdown(struct my_ssh* ssh) {
	if (ssh->channel)
		libssh2_channel_free(ssh->channel);
	if (ssh->session) {
		libssh2_session_disconnect(ssh->session,
		"Normal Shutdown, Thank you for playing");
		libssh2_session_free(ssh->session);
	}
	if (ssh->sock2) {
#ifdef WIN32
		closesocket(ssh->sock2);
#else
		close(ssh->sock2);
#endif
	}
	if (ssh->sock) {
#ifdef WIN32
		closesocket(ssh->sock);
#else
		close(ssh->sock);
#endif
	}
	if (ssh->ssh2_init)
		libssh2_exit();
}

int ErrorAndClose(struct my_ssh* ssh, const char* emsg_fmt, ...) {
	if (ssh->sock2) {
		send(ssh->sock2, "-", 1, 0);
		char buf[1024];
		va_list args;
		va_start(args, emsg_fmt);
		int plen = vsnprintf(buf, 1024, emsg_fmt, args);
		va_end(args);
		send(ssh->sock2, buf, plen, 0);
		send(ssh->sock2, "\n", 1, 0);
	} else {
		fprintf(stderr, "-");
		va_list args;
		va_start(args, emsg_fmt);
		vfprintf(stderr, emsg_fmt, args);
		va_end(args);
		fprintf(stderr, "\n");
	}
	MySshShutdown(ssh);
	return 1;
}

int ReadChar(struct my_ssh* ssh) {
	libssh2_channel_set_blocking(ssh->channel, 0);
	int tmp;
	while (1) {
		char c;
		ssize_t ret = libssh2_channel_read(ssh->channel, &c, 1);
		if (ret == 1)
			return c;
		if (ret != LIBSSH2_ERROR_EAGAIN)
			return -1;
		libssh2_keepalive_send(ssh->session, &tmp);
		struct timeval timeout;
		timeout.tv_sec = SSH_TIMEOUT;
		timeout.tv_usec = 0;
		fd_set fd;
		FD_ZERO(&fd);
		FD_SET(ssh->sock, &fd);
		fd_set *writefd = NULL;
		fd_set *readfd = NULL;
		int dir = libssh2_session_block_directions(ssh->session);
		if(dir & LIBSSH2_SESSION_BLOCK_INBOUND)
			readfd = &fd;
		if(dir & LIBSSH2_SESSION_BLOCK_OUTBOUND)
			writefd = &fd;
		int rc = select(ssh->sock + 1, readfd, writefd, NULL, &timeout);
		if (rc == SOCKET_ERROR)
			return -1;
		if (rc <= 0)
			return -1;
	}
}

int ReadUntil(struct my_ssh* ssh, const char* until_chars, char* buf, int buflen) {
	libssh2_channel_set_blocking(ssh->channel, 0);
	int buf_filled = 0;
	int tmp;
	while (1) {
		while (buf_filled < buflen) {
			char c;
			ssize_t ret = libssh2_channel_read(ssh->channel, &c, 1);
			if (ret != 1) {
				if (ret != LIBSSH2_ERROR_EAGAIN)
					return -1;
				else
					break;
			}
			buf[buf_filled] = c;
			++buf_filled;
			const char* at = until_chars;
			for (; *at != '\0'; ++at) {
				if (c == *at)
					return buf_filled;
			}
		}
		if (buf_filled >= buflen)
			return buf_filled;
		libssh2_keepalive_send(ssh->session, &tmp);
		struct timeval timeout;
		timeout.tv_sec = SSH_TIMEOUT;
		timeout.tv_usec = 0;
		fd_set fd;
		FD_ZERO(&fd);
		FD_SET(ssh->sock, &fd);
		fd_set *writefd = NULL;
		fd_set *readfd = NULL;
		int dir = libssh2_session_block_directions(ssh->session);
		if(dir & LIBSSH2_SESSION_BLOCK_INBOUND)
			readfd = &fd;
		if(dir & LIBSSH2_SESSION_BLOCK_OUTBOUND)
			writefd = &fd;
		int rc = select(ssh->sock + 1, readfd, writefd, NULL, &timeout);
		if (rc == SOCKET_ERROR)
			return -1;
		if (rc <= 0)
			return -1;
	}
}

void SendCommand(struct my_ssh* ssh, const char* cmd) {
	libssh2_channel_write(ssh->channel, cmd, strlen(cmd));
	libssh2_channel_write(ssh->channel, "\r", 1);
}

int main(int argc, char *argv[]) {
	struct my_ssh ssh = {0};
#ifdef WIN32
	WSADATA wsadata;
	WSAStartup(MAKEWORD(2,0), &wsadata);
#endif

	if (argc < 5) //query type, ip, username, password
		return ErrorAndClose(&ssh, "Must supply ip, username, password, query type");

	unsigned long hostaddr = inet_addr(argv[1]);
	const char* username = argv[2];
	const char* password = argv[3];
	const char* qtype = argv[4];
	const char* vlan_id = "0";
	const char* child_id = "";
	int out_port = 0;

	const char* ifaces_cmds[] = {
		"show interface",
		"show interface lag detail",
		0
	};
	const char* vlan_mem_cmds[] = {
		"show vlan %s",
		"show vlan %s members",
		0
	};

	const char** run_cmds;
	if (strcmp(qtype, "ifaces") == 0)
		run_cmds = ifaces_cmds;
	else if (strcmp(qtype, "vlanmem") == 0) {
		if (argc < 8)
			return ErrorAndClose(&ssh, "Must supply VLAN ID, local connect port, and child ID");
		vlan_id = argv[5];
		out_port = atoi(argv[6]);
		child_id = argv[7];
		run_cmds = vlan_mem_cmds;
	} else
		return ErrorAndClose(&ssh, "Invalid query type");

	int rc = libssh2_init(0);
	if (rc != 0)
		return ErrorAndClose(&ssh, "Failed to initialize libssh2: %d", rc);
	ssh.ssh2_init = 1;

	int sock = socket(AF_INET, SOCK_STREAM, 0);
	struct sockaddr_in sin;
	sin.sin_family = AF_INET;
	sin.sin_port = htons(22);
	sin.sin_addr.s_addr = hostaddr;
	if (connect(sock, (struct sockaddr*)(&sin), sizeof(struct sockaddr_in)) != 0) {
		return ErrorAndClose(&ssh, "Failed to connect to IP address");
	}
	ssh.sock = sock;

	if (out_port > 0 && out_port < 65536) {
		int sock2 = socket(AF_INET, SOCK_STREAM, 0);
		struct sockaddr_in sin2;
		sin2.sin_family = AF_INET;
		sin2.sin_port = htons(out_port);
		sin2.sin_addr.s_addr = inet_addr("127.0.0.1");
		if (connect(sock2, (struct sockaddr*)(&sin2), sizeof(struct sockaddr_in)) != 0) {
			return ErrorAndClose(&ssh, "Failed to connect to local port");
		}
		ssh.sock2 = sock2;
		unsigned long block = 1;
#ifdef WIN32
		ioctlsocket(sock2, FIONBIO, &block);
#else
		ioctl(sock2, FIONBIO, &block);
#endif
		send(sock2, child_id, strlen(child_id), 0);
		send(sock2, "\n", 1, 0);
		send(sock2, "\\\\\\\n", 4, 0);
		send(sock2, argv[1], strlen(argv[1]), 0);
		send(sock2, "\n", 1, 0);
	}

	LIBSSH2_SESSION* session = libssh2_session_init();
	if (libssh2_session_startup(session, sock))
		return ErrorAndClose(&ssh, "Failed to establish SSH session");
	ssh.session = session;
	libssh2_keepalive_config(session, 1, 1);

	if (libssh2_userauth_password(session, username, password) != 0)
		return ErrorAndClose(&ssh, "Authentication by password failed!");

	if (!(ssh.channel = libssh2_channel_open_session(session)))
		return ErrorAndClose(&ssh, "Unable to open a session");
	if (libssh2_channel_request_pty(ssh.channel, "vanilla") != 0)
		return ErrorAndClose(&ssh, "Failed requesting pty");
	if (libssh2_channel_shell(ssh.channel) != 0)
		return ErrorAndClose(&ssh, "Unable to request shell on allocated pty");

	char buf[8192];
	int ret = ReadUntil(&ssh, ">", buf, 8191);
	if (ret <= 0 || ret >= 8191)
		return ErrorAndClose(&ssh, "Couldn't find prompt");

	int i = 0;
	for (; run_cmds[i]; ++i) {
		sprintf(buf, run_cmds[i], vlan_id);
		SendCommand(&ssh, buf);
		int buf_at = 0;
		while (1) {
			ret = ReadChar(&ssh);
			if (ret < 0)
				return ErrorAndClose(&ssh, "Couldn't read from SSH stream");
			char c = ret;
			if (c == 8) {
				if (buf_at > 0)
					--buf_at;
				continue;
			}
			else if (c == '>')
				break;
			else if (buf_at == 8192 || c == '\n') {
				if (buf_at > 0) {
					buf[buf_at - 1] = '\0';
					if (ssh.sock2) {
						send(ssh.sock2, buf, strlen(buf), 0);
						send(ssh.sock2, "\n", 1, 0);
					} else
						printf("%s\n", buf);
				}
				buf_at = 0;
				continue;
			}
			buf[buf_at] = c;
			++buf_at;
			if (buf_at >= 8 && strncmp(buf + buf_at - 8, "--MORE--", 8) == 0) {
				libssh2_channel_write(ssh.channel, " ", 1);
				buf_at = 0;
			}
		}
	}

	if (ssh.sock2)
		send(ssh.sock2, "///\n", 4, 0);

	MySshShutdown(&ssh);
	return 0;
}
