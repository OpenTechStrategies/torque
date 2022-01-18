# torqueclient

Library to interface with a torque server running behind mediawiki.  It creates
a nicer programmattic interface with local caching options for the more cumbersome
rest interface that the torque mediawiki extension creates.

# Usage

The basic usage is:

```
from torqueclient import Torque

torque = Torque("<URL>", "<username>", "<password>")

print(torque.collections["<collection_name>"].documents["<document_key>"]["<field>"]
```

See the inline documentation for more information.
