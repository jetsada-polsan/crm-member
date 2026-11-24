import os, sys, time, socket, subprocess, pathlib, datetime, msvcrt
BASE = pathlib.Path('D:/Xamp/htdocs/sync-agent')
DATA = pathlib.Path('C:/Users/Administrator/AppData/Local/PopstarCrmAgent201')
DATA.mkdir(parents=True, exist_ok=True)
os.environ['LOCALAPPDATA'] = str(DATA.parent)
lock = (DATA / 'startup-launcher.lock').open('a+b')
lock.seek(0); lock.write(b'0'); lock.flush(); lock.seek(0)
try:
    msvcrt.locking(lock.fileno(), msvcrt.LK_NBLCK, 1)
except OSError:
    sys.exit(0)
def log(message):
    with (DATA / 'startup-launcher.log').open('a', encoding='utf-8') as f:
        f.write(str(datetime.datetime.now()) + ' ' + message + chr(10))
log('CRM Sync Agent supervisor started; target configured in monitor-settings.json')
reported = False
while True:
    probe = socket.socket()
    try:
        probe.bind(('192.168.88.201', 8770))
    except OSError:
        probe.close()
        if not reported:
            log('Port 8770 already in use or address unavailable; waiting, no duplicate started')
            reported = True
        time.sleep(15)
        continue
    probe.close()
    reported = False
    log('Starting CRM Sync Agent')
    try:
        with (DATA / 'agent-console.log').open('a', encoding='utf-8') as output:
            result = subprocess.run([sys.executable, '-u', str(BASE / 'start-monitor-vpn-201.py')], cwd=str(BASE), stdout=output, stderr=subprocess.STDOUT)
        log('Agent exited: ' + str(result.returncode) + '; retry in 15 seconds')
    except Exception as exc:
        log('Launch failed: ' + str(exc))
    time.sleep(15)
