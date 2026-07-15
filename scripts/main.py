#!/usr/bin/env python3
"""
SHOPVIVALIZ - Entry point do pipeline completo de automação com IA
Executa o pipeline item a item, com fallback e sem travar por erro individual.
"""

import sys
from pathlib import Path

sys.path.insert(0, str(Path(__file__).parent))
from automation.pipeline_orchestrator import PipelineOrchestrator


def main() -> int:
    spreadsheet = sys.argv[1] if len(sys.argv) > 1 else 'mass_update_media_info.xlsx'
    orchestrator = PipelineOrchestrator()
    result = orchestrator.run_complete_pipeline(spreadsheet)
    return 0 if result.get('status') == 'success' else 1


if __name__ == '__main__':
    sys.exit(main())
