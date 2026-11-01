"""A small reconnecting wrapper around one PyMySQL connection (autocommit; the worker is single-threaded)."""

from __future__ import annotations

import logging
import time

import pymysql
import pymysql.cursors

log = logging.getLogger("tgworker.db")


class Database:
    def __init__(self, host: str, port: int, name: str, user: str, password: str):
        self._args = dict(host=host, port=port, database=name, user=user, password=password,
                          charset="utf8mb4", autocommit=True, cursorclass=pymysql.cursors.DictCursor,
                          connect_timeout=10, read_timeout=60, write_timeout=60)
        self._conn: pymysql.connections.Connection | None = None

    def _connection(self) -> pymysql.connections.Connection:
        if self._conn is None or not self._conn.open:
            self._conn = pymysql.connect(**self._args)
        return self._conn

    def _run(self, sql: str, params, fetch: str | None):
        last_error = None
        for attempt in range(3):
            try:
                with self._connection().cursor() as cur:
                    cur.execute(sql, params)
                    if fetch == "all":
                        return cur.fetchall()
                    if fetch == "one":
                        return cur.fetchone()
                    return cur.rowcount
            except (pymysql.err.OperationalError, pymysql.err.InterfaceError) as exc:
                last_error = exc
                log.warning("db connection problem (%s), reconnecting", exc)
                try:
                    if self._conn is not None:
                        self._conn.close()
                except Exception:
                    pass
                self._conn = None
                time.sleep(min(5, 1 + attempt * 2))
        raise last_error  # type: ignore[misc]

    def all(self, sql: str, params=()) -> list[dict]:
        return list(self._run(sql, params, "all") or [])

    def one(self, sql: str, params=()) -> dict | None:
        return self._run(sql, params, "one")

    def execute(self, sql: str, params=()) -> int:
        return int(self._run(sql, params, None) or 0)

    def insert(self, sql: str, params=()) -> int:
        self._run(sql, params, None)
        return int(self._connection().insert_id())
