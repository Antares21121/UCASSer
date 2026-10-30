"""Real isolated clone, extension activation and source-preservation gate."""
import json
from pathlib import Path
import secrets
import subprocess
import sys

ROOT=Path(__file__).resolve().parents[1]
project='ucasser-stage-ci-'+secrets.token_hex(4)
target=ROOT.parent/project
assert target.resolve().parent==ROOT.parent.resolve() and target.name==project and not target.exists()
command=[sys.executable,str(ROOT/'scripts/stage_instance.py'),'--source-root',str(ROOT),'--env-file','.env',
         '--stage-root',str(target),'--project',project,'--port','18081','--create']
try:
    subprocess.run(command,cwd=ROOT,stdin=subprocess.DEVNULL,check=True)
    report=json.loads((target/'.runtime/stage-report.json').read_text(encoding='utf-8'))
    assert report['ready_for_review'] and not report['production_switched']
    assert 'flarum-messages' in report['staged']['extensions']
    for table in ['users','campus_courses','campus_course_offerings','campus_aggregation_candidates']:
        if report['source_before']['tables'].get(table)==report['source_after']['tables'].get(table):
            assert report['staged']['tables'].get(table)==report['source_before']['tables'].get(table),table
    print('PASS: separate stage, real DB clone, message extension, API and source record preservation')
finally:
    # Stop only this explicitly named isolated project. Private evidence stays on
    # the ephemeral runner and is never included in CI artifact upload paths.
    if (target/'compose.stage.json').is_file():
        subprocess.run(['docker','compose','--env-file',str(target/'.env.stage'),'-f',str(target/'compose.stage.json'),'-p',project,'down'],
            cwd=ROOT,stdin=subprocess.DEVNULL,stdout=subprocess.DEVNULL,stderr=subprocess.DEVNULL,check=False)
