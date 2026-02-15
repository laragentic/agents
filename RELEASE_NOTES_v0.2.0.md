# Release Notes for v0.2.0

## Agent Skills System

This release introduces a comprehensive Agent Skills System following the [agentskills.io](https://agentskills.io) specification. The system enables dynamic, context-aware instruction loading with progressive disclosure.

### Core Features

✅ **Progressive disclosure**: Load only what's needed  
✅ **Manual & auto-resolution** of skills  
✅ **Relevance scoring algorithm**  
✅ **Full integration** with all loops (ReAct, PlanExecute, ChainOfThought)  
✅ **Streaming support** with callbacks  
✅ **53 passing tests** with 154 assertions

### Components Added

#### src/Skills/
- `HasAgentSkills`: Main trait for agents
- `Skill`: Value object for complete skills
- `SkillMetadata`: Frontmatter data object
- `SkillResult`: Response wrapper with skill metadata
- `SkillLoader`: Filesystem discovery and loading
- `SkillRegistry`: In-memory skill storage
- `SkillResolver`: Relevance scoring and matching
- `FrontmatterParser`: YAML frontmatter parser

#### src/Exceptions/
- `SkillNotFoundException`: Missing skill exception
- `SkillValidationException`: Invalid skill format exception

### Configuration

- Added `skills` section to `config/agentic.php`
- Supports `auto_resolve`, `threshold`, `limit`, and `path` config

### Tests

53 tests with 154 assertions covering:
- Unit tests for all components
- Integration tests
- Test fixtures and example skills

### Example Skills

- **code-review**: Security, performance, best practices
- **data-analysis**: Statistical analysis and insights
- **api-testing**: API validation and testing

### Documentation

- `SKILLS_README.md`: System overview and quick start
- `tutorial/agent-skills-system.md`: Comprehensive tutorial
- Examples in `examples/skills/`

### Usage

```php
// Manual loading
$agent = (new MyAgent)
    ->withSkill('code-review')
    ->reactLoop('Review this code...');

// Auto-resolution
$agent = (new MyAgent)
    ->autoResolveSkills()
    ->reactLoop('Analyze security issues...');
```

### Key Design Principles

1. **Laravel native** - Uses config, service container
2. **Trait composition** - Works with existing traits
3. **Progressive disclosure** - Minimal context usage
4. **Fluent API** - Chainable methods
5. **Spec compliant** - agentskills.io
6. **Fully tested** - Comprehensive coverage

---

**Changes**: 33 files changed, 4,602 insertions(+), 11 deletions(-)  
**Pull Request**: #1 from laragentic/claude/agent-skills-system-i11Rh  
**Full Changelog**: https://github.com/laragentic/agents/compare/v0.1.0...v0.2.0
