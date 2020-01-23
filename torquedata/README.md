# The Torque Data App

This serves up all the data for torque (see [DESIGN-torque-data.md]).

Currently this file is a placeholder

# Usage

Right now, this usage is a bit hardcoded, but we need to retain it for
it's eventual evolution.  We use the mediawiki api to access the proposals.

This usage should get updated eventually to be more general purpose for
the link between api and spreadsheets uploaded.

In python

```python
import mwclient
site = mwclient.Site('<SERVERNAME>', path='lfc/', scheme='https')
site.login("<LOGIN>", "<PASSWORD>")
site.api('torquedataconnect', format='json', path='/proposals')
```

In shell
```
export TORQUE_TOKEN=$(eval - echo $(curl https://<SERVERNAME>/lfc/api.php --cookie-jar cookies.jar --data "action=query" --data "meta=tokens" --data "format=json" --data "type=login" | jq '.query.tokens.logintoken'))
curl https://torque.leverforchange.org/lfc/api.php --cookie @cookies.jar --cookie-jar cookies.jar --data "action=clientlogin" --data "username=<LOGIN>" --data "password=<PASSWORD>" --data "format=json" --data-urlencode "logintoken=$TORQUE_TOKEN"  --data "loginreturnurl=http://google.com/"
curl https://torque.leverforchange.org/lfc/api/proposals --cookie @cookie.jar
curl https://torque.leverforchange.org/lfc/api/proposals --cookie @cookie.jar | jq ".proposals | length"
```
