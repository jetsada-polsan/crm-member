import json
import ssl
import urllib.error
import urllib.request
from urllib.parse import urlparse


def send_batch(base_url, token, kind, body_json, timeout=25):
    parsed=urlparse(base_url)
    if parsed.scheme!='https' or not parsed.hostname:
        raise ValueError('SYNC_BASE_URL must be an https URL with a valid hostname')
    if len(token)<32:
        raise ValueError('SYNC_API_TOKEN must be at least 32 chars')
    url=base_url.rstrip('/')+'/api/sync/v1/'+kind
    request=urllib.request.Request(url,data=body_json.encode('utf-8'),method='POST',headers={
        'Authorization':'Bearer '+token,'Content-Type':'application/json; charset=utf-8',
        'Accept':'application/json','User-Agent':'PopstarSyncAgent/2.0'
    })
    with urllib.request.urlopen(request,timeout=timeout,context=ssl.create_default_context()) as response:
        if response.status!=200:
            raise RuntimeError(f'Unexpected status {response.status}')
        payload=json.loads(response.read().decode('utf-8'))
        if payload.get('ok') is not True:
            raise RuntimeError('Cloud API did not acknowledge batch')
        return payload


def get_cursors(base_url, token, source, timeout=15):
    """Shared server-side cursor (also advanced by the LAN pull)."""
    from urllib.parse import quote
    url=base_url.rstrip('/')+'/api/sync/v1/cursors?source='+quote(source)
    req=urllib.request.Request(url,headers={'Authorization':'Bearer '+token,'Accept':'application/json','User-Agent':'PopstarSyncAgent/2.0'})
    with urllib.request.urlopen(req,timeout=timeout,context=ssl.create_default_context()) as r:
        return json.loads(r.read().decode('utf-8'))
