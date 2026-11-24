import json
import os
import ssl
import urllib.error
import urllib.request
from pathlib import Path
from urllib.parse import quote, urlparse


HOME_CRM_VPN_URL = "https://192.168.99.143:18443"


class NoRedirect(urllib.request.HTTPRedirectHandler):
    def redirect_request(self, *args, **kwargs):
        return None


def open_crm_request(base_url, request, timeout):
    if base_url.rstrip("/") == HOME_CRM_VPN_URL and os.getenv("LOCALAPPDATA"):
        folder = Path(os.environ["LOCALAPPDATA"]) / "PopstarCrmAgent201" / "crm-vpn"
        context = ssl.SSLContext(ssl.PROTOCOL_TLS_CLIENT)
        context.load_verify_locations(cafile=folder / "server.pem")
        context.load_cert_chain(folder / "client.pem", folder / "client.key")
        opener = urllib.request.build_opener(
            urllib.request.ProxyHandler({}),
            urllib.request.HTTPSHandler(context=context),
            NoRedirect(),
        )
        return opener.open(request, timeout=timeout)
    return urllib.request.urlopen(request, timeout=timeout)



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
    with open_crm_request(base_url, request, timeout) as response:
        if response.status!=200:
            raise RuntimeError(f'Unexpected status {response.status}')
        payload=json.loads(response.read().decode('utf-8'))
        if payload.get('ok') is not True:
            raise RuntimeError('Cloud API did not acknowledge batch')
        return payload


def get_invoice_cursor(base_url, token, source, timeout=15):
    """Invoice cursor shared with the CRM's LAN pull, so both sync paths resume from the same place."""
    url=base_url.rstrip('/')+'/api/sync/v1/cursors?source='+quote(source)
    request=urllib.request.Request(url,method='GET',headers={
        'Authorization':'Bearer '+token,'Accept':'application/json','User-Agent':'PopstarSyncAgent/2.0'
    })
    with open_crm_request(base_url, request, timeout) as response:
        payload=json.loads(response.read().decode('utf-8'))
        if payload.get('ok') is not True:
            raise RuntimeError('Cloud API did not return the invoice cursor')
        return int(payload.get('invoices') or 0)
