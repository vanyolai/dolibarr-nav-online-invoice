from pathlib import Path
import re

# Synchronization must not execute DDL. Keep the migration routine, but expose it
# explicitly for module activation/upgrade only.
p = Path('class/navinvoicesync.class.php')
text = p.read_text()
text, n = re.subn(r'^\s*\$this->ensureSchema\(\);\s*\n', '', text, count=1, flags=re.M)
if n != 1:
    raise SystemExit('sync runtime ensureSchema call not found exactly once')
old = """    /**
     * Backward-compatible schema migration for installations upgraded in place.
     * New installs use the SQL definitions under sql/.
     */
    public function ensureSchema(): void
"""
new = """    /**
     * Backward-compatible schema migration for installations upgraded in place.
     * New installs use the SQL definitions under sql/. This method is invoked
     * only from module activation/upgrade; normal synchronization never mutates
     * database schema.
     */
    public function migrateLegacySchema(): void
"""
if text.count(old) != 1:
    raise SystemExit('sync schema method marker not found')
text = text.replace(old, new, 1)
if 'ensureSchema' in text:
    raise SystemExit('sync still contains ensureSchema')
p.write_text(text)

# Module activation owns migration. Existing tables are migrated first, then
# Dolibarr's SQL loader installs/updates the canonical definitions.
p = Path('core/modules/modNavInvoice.class.php')
text = p.read_text()
start = text.find("    public function init($options = '')\n")
remove = text.find("    public function remove($options = '')\n", start)
if start < 0 or remove < 0:
    raise SystemExit('module init/remove boundaries not found')
init_block = """    public function init($options = '')
    {
        $invoiceTable = MAIN_DB_PREFIX.'navinvoice_invoice';
        if ($this->tableExists($invoiceTable)) {
            require_once dirname(__DIR__, 2).'/class/navinvoicesync.class.php';
            try {
                $migrator = new NavInvoiceSync($this->db);
                $migrator->migrateLegacySchema();
            } catch (Throwable $e) {
                $this->error = 'NAV legacy schema migration failed: '.$e->getMessage();
                return -1;
            }
        }

        $result = $this->_load_tables('/navinvoice/sql/');
        if ($result < 0) {
            return -1;
        }

        $this->remove($options);
        $sql = array();
        return $this->_init($sql, $options);
    }

"""
text = text[:start] + init_block + text[remove:]
prep = text.find('    /**\n     * Prepare schema upgrades whose new indexes reuse an existing legacy name.')
table = text.find('    private function tableExists(string $table): bool\n', prep)
if prep < 0 or table < 0:
    raise SystemExit('module duplicate migration block not found')
text = text[:prep] + text[table:]
# The splice moves tableExists() to the old migration-block start; search from
# that new position rather than the pre-splice offset.
idx = text.find('    /** @return string[] */\n    private function indexColumns', prep)
if idx >= 0:
    method_end = text.rfind('\n}')
    if method_end <= idx:
        raise SystemExit('module index helper end not found')
    text = text[:idx] + text[method_end:]
if 'prepareLegacySchemaUpgrade' in text or 'indexColumns(' in text:
    raise SystemExit('module duplicate schema helpers remain')
p.write_text(text)

Path('audit-refactor-debug.txt').unlink(missing_ok=True)
