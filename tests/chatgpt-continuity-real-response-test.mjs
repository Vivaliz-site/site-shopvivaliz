import test from 'node:test';
import assert from 'node:assert/strict';
import {
  assistantSnapshot,
  confirmAssistantProgress,
} from '../scripts/chatgpt-continuity/chatgpt-continuity-bridge-worker.mjs';

test('generic main-surface growth cannot certify a real assistant response', async () => {
  const baseline = await assistantSnapshot({
    async evaluate() {
      return {
        count: 0,
        lastText: '',
        lastLength: 0,
        lastKey: '',
        surfaceText: 'Pensando',
        surfaceLength: 8,
        conversationPath: '/c/real-response-thread',
      };
    },
  });

  const cdp = {
    async evaluate(source) {
      const expression = String(source);
      if (expression.includes('conversation-turn-state')) {
        return {
          http_status: 200,
          node_id: 'assistant-old',
          role: 'assistant',
          end_turn: true,
          content_text_length: 17,
          message_status: 'finished_successfully',
        };
      }
      if (expression.includes('conversationPath:String')) {
        return {
          count: 0,
          lastText: '',
          lastLength: 0,
          lastKey: '',
          surfaceText: 'Pensando\natividade de ferramenta aumentou',
          surfaceLength: 39,
          conversationPath: '/c/real-response-thread',
        };
      }
      return false;
    },
  };

  const confirmed = await confirmAssistantProgress(cdp, baseline, 1000, 10);
  assert.equal(confirmed, false, 'only assistant-turn content may certify progress');
});

test('new assistant-turn content certifies a real response on the bound conversation', async () => {
  const baseline = await assistantSnapshot({
    async evaluate() {
      return {
        count: 1,
        lastText: 'resposta anterior',
        lastLength: 17,
        lastKey: 'turn-old',
        surfaceText: 'resposta anterior',
        surfaceLength: 17,
        conversationPath: '/c/real-response-thread',
      };
    },
  });

  let turnProbe = 0;
  const cdp = {
    async evaluate(source) {
      const expression = String(source);
      if (expression.includes('conversation-turn-state')) {
        turnProbe += 1;
        return turnProbe === 1
          ? {
              http_status: 200,
              node_id: 'user-continue',
              role: 'user',
              end_turn: true,
              content_text_length: 8,
              message_status: 'finished_successfully',
            }
          : {
              http_status: 200,
              node_id: 'assistant-new',
              role: 'assistant',
              end_turn: true,
              content_text_length: 31,
              message_status: 'finished_successfully',
            };
      }
      if (expression.includes('conversationPath:String')) {
        return {
          count: 2,
          lastText: 'nova resposta real do assistente',
          lastLength: 31,
          lastKey: 'turn-new',
          surfaceText: 'nova resposta real do assistente',
          surfaceLength: 31,
          conversationPath: '/c/real-response-thread',
        };
      }
      return false;
    },
  };

  const confirmed = await confirmAssistantProgress(cdp, baseline, 1000, 10);
  assert.equal(confirmed, true, 'a completed backend assistant response on the bound conversation must certify progress');
});
