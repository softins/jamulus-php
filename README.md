# jamulus-php
PHP script to fetch info from Jamulus servers and return as JSON

Used as a back-end by [Jamulus Explorer](https://github.com/softins/jamulus-web)

Normally invoked as `servers.php?central=central.server.host:port`, which will
return data for all servers registered with that central server.

Can also be invoked as `servers.php?server=individual.server.host:port`, which will
return data just for the specified individual server. Note that in this case,
the server name, city and country cannot be returned, as they are only available
from a central server.
