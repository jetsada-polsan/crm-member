from pathlib import Path

def read_customers(connection_string):
    # Requires pyodbc + ODBC Driver 18 and SELECT-only SQL account, reachable through VPN.
    import pyodbc
    sql=(Path(__file__).resolve().parents[1]/'queries'/'customers.sql').read_text(encoding='utf8')
    with pyodbc.connect(connection_string,timeout=15,autocommit=True) as connection:
        cursor=connection.cursor()
        cursor.execute(sql)
        cols=[col[0] for col in cursor.description]
        rows=[]
        for row in cursor.fetchall():
            d=dict(zip(cols,row))
            rows.append({
                'erp_customer_code':str(d['erp_customer_code']).strip(),
                'customer_name':str(d['customer_name']).strip(),
                'phone':str(d['phone']).strip() if d.get('phone') is not None else None,
                'salesperson_code':str(d['salesperson_code']).strip() if d.get('salesperson_code') is not None else None,
            })
        return rows
