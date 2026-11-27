# shellcheck shell=bash
# Shared by backup.sh and restore-drill.sh: select PostgreSQL client tools
# whose major version matches the server. A newer pg_dump writes settings the
# older server does not know (e.g. pg_dump 17+ emits
# "SET transaction_timeout"), so such a backup cannot be restored into the
# same server version — exactly what a disaster recovery needs.
#
# Order: $PG_BIN_DIR, then /usr/lib/postgresql/<server major>/bin (Debian/
# Ubuntu layout), then whatever is on PATH. Fails if the major versions differ.

pg_major_of() {
  "$1" --version | sed -E 's/.* ([0-9]+)(\.[0-9]+)?.*/\1/'
}

select_pg_tools() {
  local server_num server_major candidate client_major
  server_num="$(psql --no-psqlrc --tuples-only --no-align --command 'show server_version_num' "$@")" \
    || { echo "Cannot connect to PostgreSQL to read the server version." >&2; return 1; }
  server_major=$(( server_num / 10000 ))

  for candidate in "${PG_BIN_DIR:-}" "/usr/lib/postgresql/${server_major}/bin" ""; do
    if [[ -n "$candidate" && -x "$candidate/pg_dump" ]]; then
      PG_BIN="$candidate/"
      break
    elif [[ -z "$candidate" ]]; then
      PG_BIN=""
    fi
  done

  client_major="$(pg_major_of "${PG_BIN}pg_dump")"
  if [[ "$client_major" != "$server_major" ]]; then
    echo "PostgreSQL client tools are version ${client_major} but the server is ${server_major}." >&2
    echo "Install postgresql-client-${server_major} or set PG_BIN_DIR to its bin directory." >&2
    return 1
  fi

  export PG_BIN
  echo "Using PostgreSQL ${server_major} client tools${PG_BIN:+ from ${PG_BIN%/}}"
}
