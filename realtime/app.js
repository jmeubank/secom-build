var express = require('express'),
    faye = require('faye'),
    util = require('util'),
    http = require('http'),
    daemon = null;

if (!process.argv || process.argv.length < 3 || process.argv[2] != 'nodaemon')
  daemon = require('daemon');

var byx = new faye.NodeAdapter({mount: '/faye'});

var server = express.createServer();
server.use(express.bodyParser());
server.post('/', function(req, res){
  var payload = JSON.parse(req.body.payload);
  byx.getClient().publish(req.body.channel_name, payload);
  res.end();
  if (!daemon)
    util.log(util.inspect(req.body));
});

byx.attach(server);
server.listen(8001);

if (daemon) {
  var log_path = __dirname + '/realtime.log';
  var pid_path = __dirname + '/../realtime.pid';
  require('fs').unlinkSync(log_path);
  daemon.daemonize(log_path, pid_path, function(err, pid){
    if (err)
      return util.log('Error starting realtime daemon: ' + err);
    util.log('Realtime daemon successfully started with pid: ' + pid);
  });
}
