# Server-Sent Events (SSE) Documentation Index

**Updated:** February 15, 2026

All Laragentic agentic loops now use **Server-Sent Events (SSE)** with `response()->eventStream()` as the official recommended approach for streaming real-time progress to your frontend.

## 📖 Documentation Map

### Start Here (Choose Your Path)

#### 🚀 Quick Start (5 min)
- **README.md** — "Quick Start: Streaming Agent Progress" section
- **QUICK_SSE_GUIDE.md** — One-page reference

#### 📚 Learning Path (30 min)
1. **QUICK_SSE_GUIDE.md** — Understand the basics
2. **tutorial/streaming-sse-react-loop.md** — See a complete ReAct example
3. **tutorial/quick-reference.md** — Learn all callbacks

#### 🔧 Implementation (varies)
1. **tutorial/streaming-sse-react-loop.md** or **streaming-sse-plan-execute-loop.md** — Choose your loop type
2. **testlaragentic/routes/demos.php** — See working code
3. **tutorial/quick-reference.md** — Reference callbacks

#### ↪️ Migration (15 min)
1. **tutorial/SSE_MIGRATION.md** — Before/after comparison
2. **tutorial/quick-reference.md** — Update callback patterns

---

## 📄 Document Reference

### Top-Level Documentation

| File | Purpose | Read Time |
|------|---------|-----------|
| **README.md** | Package overview + quick start example | 5 min |
| **QUICK_SSE_GUIDE.md** | One-page SSE reference | 3 min |
| **SSE_UPDATE_SUMMARY.md** | Comprehensive update guide | 15 min |
| **SSE_INDEX.md** | This document | 2 min |

### Tutorial Folder: Main Guides

| File | Purpose | Length | Audience |
|------|---------|--------|----------|
| **tutorial/README.md** | Tutorial index + setup | 1.5 KB | Beginners |
| **tutorial/streaming-sse-react-loop.md** | Complete ReAct guide ✨ | 11.5 KB | Implementers |
| **tutorial/streaming-sse-plan-execute-loop.md** | Complete Plan-Execute guide ✨ | 15.3 KB | Implementers |
| **tutorial/quick-reference.md** | Callback reference | 12.5 KB | All developers |
| **tutorial/complete-example.md** | Full working chat example | 17.5 KB | Advanced |
| **tutorial/SSE_MIGRATION.md** | Migration guide | 5.6 KB | Migrating |

### Tutorial Folder: Reference (Deprecated)

| File | Status | Alternative |
|------|--------|-------------|
| **tutorial/streaming-react-loop.md** | ⚠️ Deprecated | Use **streaming-sse-react-loop.md** |
| **tutorial/streaming-plan-execute-loop.md** | ⚠️ Deprecated | Use **streaming-sse-plan-execute-loop.md** |

---

## 🎯 Finding What You Need

### "How do I stream an agent's progress?"
→ **README.md** "Quick Start" section (code example provided)

### "What events are available for ReAct loops?"
→ **tutorial/quick-reference.md** "ReAct Loop Callbacks" section

### "What events are available for Plan-Execute loops?"
→ **tutorial/quick-reference.md** "Plan-Execute Loop Callbacks" section

### "I want a complete working example"
→ **tutorial/streaming-sse-react-loop.md** (ReAct) or **streaming-sse-plan-execute-loop.md** (Plan-Execute)

### "How do I consume SSE in React?"
→ **tutorial/streaming-sse-react-loop.md** "Frontend: React Hook" section

### "How do I consume SSE in Vue?"
→ **tutorial/streaming-sse-react-loop.md** "Frontend: Vue 3 Composition API" section

### "How do I consume SSE with plain JavaScript?"
→ **tutorial/streaming-sse-react-loop.md** "Frontend: Consuming with JavaScript" section

### "I'm migrating from response()->stream()"
→ **tutorial/SSE_MIGRATION.md** (before/after code)

### "I want to test the examples"
→ **testlaragentic/routes/demos.php** (6 working endpoints)

### "How do I test with curl?"
→ **QUICK_SSE_GUIDE.md** "Testing Guide" section

### "What's the event reference?"
→ **QUICK_SSE_GUIDE.md** "Event Names Reference" section

---

## 🧪 Testing & Demo Routes

All demo routes are in **testlaragentic/routes/demos.php**:

```bash
# View available routes
curl http://localhost:8000/demos

# Test ReAct loop
curl "http://localhost:8000/demos/sse-react-full?query=What%20is%20Laravel?"

# Test Plan-Execute loop
curl "http://localhost:8000/demos/sse-plan-execute-full?task=Build%20an%20app"
```

See **QUICK_SSE_GUIDE.md** "Testing Guide" for more examples.

---

## 📊 Event Reference

### ReAct Loop Events
```
thinking              →  iteration_start  →  thought  →  action_start  →  observation
↓                                                                            ↓
(Loop initialization)                                                (If more iterations needed, back to "iteration_start")
↓
complete (or max_iterations if limit reached)
```

| Event | Data | When |
|-------|------|------|
| `thinking` | `{message}` | Loop starts |
| `iteration_start` | `{iteration}` | New iteration |
| `thought` | `{iteration, content}` | LLM reasoning done |
| `action_start` | `{iteration, tool, args}` | Before tool call |
| `observation` | `{iteration, tool, result}` | Tool result received |
| `complete` | `{answer, iterations}` | Loop complete |
| `max_iterations` | `{iterations}` | Limit reached |

### Plan-Execute Loop Events
```
started  →  plan  →  step_start  →  step_complete  →  (repeat or replan)
↓                                                         ↓
                                              synthesis_start  →  synthesis_complete
                                                                   ↓
                                                              complete
```

| Event | Data | When |
|-------|------|------|
| `started` | `{message}` | Execution begins |
| `plan` | `{steps[], total_steps}` | Plan created |
| `step_start` | `{step_number, description}` | Step starts |
| `step_complete` | `{step_number, description, result}` | Step done |
| `replan` | `{step, reason, new_plan[]}` | Plan adjusted |
| `synthesis_start` | `{message, step_count}` | Combining results |
| `synthesis_complete` | `{summary}` | Results combined |
| `complete` | `{answer, total_steps}` | Success |
| `max_steps` | `{steps, message}` | Limit reached |

---

## 💡 Common Patterns

### Backend: Simple ReAct Stream
```php
return response()->eventStream(function () use ($agent) {
    $agent
        ->onAfterAction(fn($tool, $args, $result, $i) => 
            yield new StreamedEvent(event: 'action', 
                data: ['tool' => $tool, 'result' => $result]))
        ->reactLoop($query);
});
```

### Frontend: React Hook
```jsx
import { useEventStream } from '@laravel/stream-react';
const { message } = useEventStream('/api/chat');
```

### Frontend: Vue Hook
```vue
<script setup>
import { useEventStream } from '@laravel/stream-vue';
const { message } = useEventStream('/api/chat');
</script>
```

### Frontend: Plain JavaScript
```javascript
const source = new EventSource('/api/chat');
source.addEventListener('action', (e) => console.log(JSON.parse(e.data)));
source.onerror = () => source.close();
```

---

## ✅ Verification Checklist

Use this to verify you're implementing SSE correctly:

- [ ] Using `response()->eventStream()` instead of `response()->stream()`
- [ ] Wrapping yields in `StreamedEvent` instances
- [ ] Each `StreamedEvent` has an `event` name
- [ ] Data is passed as an array (auto-JSON encoded)
- [ ] Frontend uses `new EventSource(url)` or Laravel Stream package
- [ ] Event listeners match event names
- [ ] JSON.parse() on received data
- [ ] source.close() called on `complete` event
- [ ] Tested with curl first
- [ ] Tested with browser EventSource or React/Vue

---

## 📚 Reading Recommendations

**For Beginners:**
1. README.md (Overview)
2. QUICK_SSE_GUIDE.md (Reference)
3. testlaragentic/routes/demos.php (Examples)

**For Implementers:**
1. tutorial/streaming-sse-react-loop.md or streaming-sse-plan-execute-loop.md
2. tutorial/quick-reference.md (Callbacks)
3. testlaragentic/routes/demos.php (Testing)

**For Advanced Use:**
1. tutorial/complete-example.md (Full application)
2. tutorial/SSE_MIGRATION.md (Custom patterns)
3. tutorial/quick-reference.md (All options)

---

## 🔗 Related Files

**Package Config:**
- `composer.json` — Dependencies

**Source Code:**
- `src/Loops/ReActLoop.php` — ReAct implementation
- `src/Loops/PlanExecuteLoop.php` — Plan-Execute implementation

**Testing:**
- `tests/Feature/StreamingResponseTest.php` — Stream tests
- `testlaragentic/routes/demos.php` — Demo endpoints

---

## 📞 Support Resources

- **Quick Reference**: QUICK_SSE_GUIDE.md
- **Full Docs**: SSE_UPDATE_SUMMARY.md
- **Working Code**: testlaragentic/routes/demos.php
- **Callbacks**: tutorial/quick-reference.md
- **Migration**: tutorial/SSE_MIGRATION.md
- **Examples**: tutorial/streaming-sse-*.md files

---

## 🎓 Learning Path

### Beginner (Day 1)
1. ✅ Read README.md "Quick Start"
2. ✅ Read QUICK_SSE_GUIDE.md
3. ✅ Run: `curl http://localhost:8000/demos`

### Intermediate (Day 2)
1. ✅ Read tutorial/streaming-sse-react-loop.md
2. ✅ Test each demo route with curl
3. ✅ Reference tutorial/quick-reference.md

### Advanced (Day 3+)
1. ✅ Implement with your own agent
2. ✅ Add custom event names
3. ✅ Integrate React/Vue frontend
4. ✅ Deploy to production

---

## Last Updated

**February 15, 2026**

All documentation reflects the latest SSE implementation for Laragentic agentic loops.

---

**SSE is now the official recommended approach for streaming Laragentic agentic loops.**
