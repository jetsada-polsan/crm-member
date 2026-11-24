import hashlib
import json
import sqlite3
import uuid
from pathlib import Path


def canonical_json(obj):
    return json.dumps(obj, sort_keys=True, ensure_ascii=False, separators=(',', ':'))

class PendingQueue:
    def __init__(self, filename):
        self.filename = str(filename)
        Path(self.filename).parent.mkdir(parents=True, exist_ok=True)
        with self.connect() as db:
            db.execute("""CREATE TABLE IF NOT EXISTS outgoing (
              id INTEGER PRIMARY KEY AUTOINCREMENT,
              batch_id TEXT NOT NULL UNIQUE,
              kind TEXT NOT NULL,
              fingerprint TEXT NOT NULL,
              body_json TEXT NOT NULL,
              status TEXT NOT NULL DEFAULT 'pending',
              created_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,
              sent_at TEXT
            )""")
            db.execute('CREATE INDEX IF NOT EXISTS idx_outgoing_status ON outgoing(status,id)')

    def connect(self):
        return sqlite3.connect(self.filename,timeout=15)

    def enqueue(self, kind, source, rows):
        if not rows:
            return None
        if len(rows)>250:
            raise ValueError('Batch must contain max 250 rows')
        normalized={'source':source,'records':rows}
        fingerprint=hashlib.sha256(canonical_json(normalized).encode('utf-8')).hexdigest()
        with self.connect() as db:
            existing=db.execute("SELECT batch_id FROM outgoing WHERE fingerprint=? AND kind=? AND status='pending' LIMIT 1",(fingerprint,kind)).fetchone()
            if existing:
                return existing[0]
            batch_id=str(uuid.uuid4())
            body=dict(normalized,batch_id=batch_id)
            db.execute('INSERT INTO outgoing(batch_id,kind,fingerprint,body_json) VALUES(?,?,?,?)',
                (batch_id,kind,fingerprint,canonical_json(body)))
            return batch_id

    def pending(self):
        with self.connect() as db:
            return db.execute("SELECT id,kind,body_json FROM outgoing WHERE status='pending' ORDER BY id ASC").fetchall()

    def mark_sent(self, record_id):
        with self.connect() as db:
            db.execute("UPDATE outgoing SET status='sent',sent_at=CURRENT_TIMESTAMP WHERE id=?",(record_id,))

    def purge_sent(self, keep_days=3):
        """Drop acknowledged batches older than keep_days; pending ones are never touched."""
        with self.connect() as db:
            return db.execute("DELETE FROM outgoing WHERE status='sent' AND sent_at < datetime('now', ?)",
                (f'-{int(keep_days)} days',)).rowcount

    def counts(self):
        with self.connect() as db:
            return dict(db.execute('SELECT status,COUNT(*) FROM outgoing GROUP BY status').fetchall())
