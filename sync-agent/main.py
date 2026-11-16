import json
import os
import sys
from pathlib import Path
from urllib.error import HTTPError,URLError
from agent.queue import PendingQueue
from agent.http_client import send_batch

KINDS={'customers','products','invoices'}


def queue_path():
    specified=os.getenv('SYNC_QUEUE_PATH')
    if specified:return Path(specified)
    local=os.getenv('LOCALAPPDATA') or str(Path.home())
    return Path(local)/'PopstarSync'/'queue.sqlite3'


def demo_rows():
    return [{'erp_customer_code':'DEMO001','customer_name':'ร้านค้าทดสอบจากเครื่องบ้าน',
             'phone':None,'salesperson_code':'SALE01'}]


def flush(q,base_url,token):
    rows=q.pending()
    for row_id,kind,body_json in rows:
        try:
            reply=send_batch(base_url,token,kind,body_json)
            if reply.get('batch_id') != json.loads(body_json)['batch_id']:
                raise RuntimeError('Batch ID mismatch: not acknowledging')
            q.mark_sent(row_id)
            print('ACK',kind,reply['batch_id'])
        except (HTTPError,URLError,TimeoutError,OSError,ValueError,RuntimeError) as error:
            print('Delivery failed; queue retained:',type(error).__name__,file=sys.stderr)
            return 1
    print('Queue summary:',q.counts())
    return 0


def main():
    mode=sys.argv[1] if len(sys.argv)>1 else 'help'
    if mode not in ('demo','customers','flush'):
        print('Usage: python main.py demo | customers | flush')
        return 2
    base_url=os.getenv('SYNC_BASE_URL','')
    token=os.getenv('SYNC_API_TOKEN','')
    source=os.getenv('SYNC_SOURCE','BPLUS')
    if not base_url.startswith('https://') or len(token)<32:
        print('Set SYNC_BASE_URL=https://... and long SYNC_API_TOKEN (>=32 chars)',file=sys.stderr)
        return 2
    q=PendingQueue(queue_path())
    if mode=='demo':
        bid=q.enqueue('customers','HOME_TEST',demo_rows())
        print('Demo queued:',bid)
    if mode=='customers':
        conn=os.getenv('SQL_CONNECTION_STRING','')
        if not conn:
            print('SQL_CONNECTION_STRING not configured',file=sys.stderr)
            return 2
        from agent.sql_reader import read_customers
        rows=read_customers(conn)
        for offset in range(0,len(rows),250):
            q.enqueue('customers',source,rows[offset:offset+250])
        print('SQL customer rows:',len(rows))
    return flush(q,base_url,token)

if __name__=='__main__':
    raise SystemExit(main())
