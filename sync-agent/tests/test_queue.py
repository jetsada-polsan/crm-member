import tempfile
import unittest
from pathlib import Path
from agent.queue import PendingQueue

class TestPendingQueue(unittest.TestCase):
    def test_deduplicate_pending_retry(self):
        with tempfile.TemporaryDirectory() as folder:
            q=PendingQueue(Path(folder)/'test.db')
            row=[{'erp_customer_code':'C001','customer_name':'ร้าน A'}]
            first=q.enqueue('customers','HOME_TEST',row)
            self.assertEqual(first,q.enqueue('customers','HOME_TEST',row))
            self.assertEqual(len(q.pending()),1)
            q.mark_sent(q.pending()[0][0]);self.assertEqual(len(q.pending()),0)
            self.assertNotEqual(first,q.enqueue('customers','HOME_TEST',row))
    def test_max_batch(self):
        with tempfile.TemporaryDirectory() as folder:
            q=PendingQueue(Path(folder)/'t.db')
            with self.assertRaises(ValueError):q.enqueue('customers','BPLUS',[{'id':i} for i in range(251)])
if __name__=='__main__':unittest.main()
