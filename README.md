# jamulus-php
PHP script to fetch info from Jamulus servers and return as JSON

Used as a back-end by [Jamulus Explorer](https://github.com/softins/jamulus-web)
and [Jamulus.Live](https://jamulus.live).

Normally invoked as `servers.php?directory=directory.server.host:port`, which will
return data for all servers registered with that directory server.

Can also be invoked as `servers.php?server=individual.server.host:port`, which will
return data just for the specified individual server. Note that in this case,
the server name, city and country cannot be returned, as they are only available
from a directory server.

Finally, it is possible to query a server's welcome message using
`servers.php?query=individual.server.host:port`, which will briefly initiate
an audio connection to the server, collect any welcome message returned,
and disconnect again. This happens quickly, so no additional client is displayed
to the other clients, but if a server is already full, it does not work.

## Firewall requirements

In order to support "port2" detection, this back-end needs to be able to accept
server replies coming from _any_ port, not just from the expected port to which
a request was sent.

To allow this, the `server.php` back-end sets its source port in a specific range,
22134-22149 (See the defines for `CLIENT_PORT` and `CLIENT_PORTS_TO_TRY`).
The system hosting the back-end needs to have its firewall configured to accept incoming
traffic to that specific port range from _any_ IP address and _any_ port number.
