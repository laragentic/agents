# Agent Skills System

A complete implementation of the [agentskills.io](https://agentskills.io) specification for Laragentic, enabling dynamic, context-aware instruction loading for AI agents.

## Overview

The Agent Skills System allows agents to load specialized knowledge and capabilities on-demand, minimizing context usage while maximizing effectiveness through progressive disclosure.

## Features

✅ **Progressive Disclosure**: Load only what you need, when you need it
✅ **Manual & Auto-Resolution**: Explicit skill loading or automatic discovery
✅ **Relevance Scoring**: Intelligent skill matching based on query content
✅ **Modular & Reusable**: Organize specialized instructions into shareable skills
✅ **Full Integration**: Works with all Laragentic loops (ReAct, PlanExecute, ChainOfThought)
✅ **Streaming Support**: Real-time skill activation in streaming contexts
✅ **Comprehensive Testing**: 53 tests with 154 assertions

## Quick Start

### 1. Add the Trait

```php
use Laragentic\Skills\HasAgentSkills;

class MyAgent
{
    use ReActLoop, HasAgentSkills;

    public function instructions(?string $query = null): string
    {
        return $this->enhanceInstructionsWithSkills(
            'You are a helpful assistant.',
            $query ?? ''
        );
    }
}
```

### 2. Load Skills

**Manual Loading**:
```php
$agent = new MyAgent;
$agent->withSkill('code-review');
$result = $agent->reactLoop('Review this PHP code...');
```

**Auto-Resolution**:
```php
$agent = new MyAgent;
$agent->autoResolveSkills(threshold: 0.3, limit: 3);
$result = $agent->reactLoop('Analyze security vulnerabilities...');
// Automatically loads relevant skills based on query
```

### 3. Create Custom Skills

```bash
mkdir -p app/Skills/my-skill
```

Create `app/Skills/my-skill/SKILL.md`:
```markdown
---
name: my-skill
description: What this skill does
tags: [relevant, tags]
version: 1.0.0
---

# My Skill

Detailed instructions for the agent...
```

## Architecture

### Core Components

- **`Skill`**: Value object representing a complete skill
- **`SkillMetadata`**: Frontmatter data (name, description, tags, etc.)
- **`SkillLoader`**: Filesystem discovery and loading
- **`SkillRegistry`**: In-memory skill storage
- **`SkillResolver`**: Relevance scoring and matching
- **`HasAgentSkills`**: Main trait for agents

### Directory Structure

```
src/Skills/
├── HasAgentSkills.php       # Main trait
├── Skill.php                # Skill value object
├── SkillMetadata.php        # Metadata value object
├── SkillResult.php          # Response wrapper
├── SkillLoader.php          # File system loading
├── SkillRegistry.php        # In-memory storage
├── SkillResolver.php        # Relevance scoring
└── FrontmatterParser.php    # YAML parsing
```

## Example Skills

Three production-ready skills are included:

### code-review
Comprehensive code review for security, performance, and best practices.
- Security analysis (OWASP Top 10)
- Performance optimization
- Code quality and SOLID principles
- Language-specific guidance (PHP, JavaScript, Python)

### data-analysis
Expert data analysis, statistical modeling, and insight generation.
- Exploratory data analysis
- Statistical testing
- Visualization recommendations
- Actionable insights

### api-testing
API endpoint testing, validation, and test suite generation.
- HTTP method testing
- Schema validation
- Security testing
- Performance analysis
- Error handling assessment

## Configuration

Add to `config/agentic.php`:

```php
'skills' => [
    'enabled' => true,
    'path' => app_path('Skills'),
    'auto_resolve' => false,
    'resolution_threshold' => 0.3,
    'resolution_limit' => 3,
],
```

## Testing

All tests passing:

```bash
./vendor/bin/pest tests/Unit/Skills tests/Feature/SkillIntegrationTest.php

Tests:    53 passed (154 assertions)
```

### Test Coverage

- ✅ Skill metadata parsing and validation
- ✅ YAML frontmatter parsing
- ✅ Skill loading and discovery
- ✅ Registry management
- ✅ Relevance scoring algorithm
- ✅ Agent integration
- ✅ Callback system
- ✅ Auto-resolution
- ✅ Progressive disclosure

## Documentation

- **[Tutorial](tutorial/agent-skills-system.md)**: Complete guide with examples
- **[Examples](examples/skills/)**: Three production-ready skills
- **[Tests](tests/Unit/Skills/)**: Unit tests with usage examples

## Advanced Usage

### Callbacks

```php
$agent = (new MyAgent)
    ->autoResolveSkills()
    ->onSkillLoaded(function ($skill) {
        Log::info('Skill loaded: ' . $skill->name());
    })
    ->onSkillResolved(function ($skills, $query) {
        Log::info('Resolved skills', [
            'count' => count($skills),
            'query' => $query
        ]);
    });
```

### Streaming

```php
foreach ($agent->reactLoopStream('Analyze this...') as $event) {
    // Real-time skill activation and execution
    echo $event;
}
```

### Custom Resolution

```php
class CustomAgent
{
    use HasAgentSkills;

    protected function resolveSkillsForQuery(string $query): void
    {
        if ($this->requiresDeepAnalysis($query)) {
            $this->withSkills(['code-review', 'security-audit']);
        } else {
            parent::resolveSkillsForQuery($query);
        }
    }
}
```

## Key Design Principles

1. **Laravel Native**: Follows Laravel conventions, uses Laravel features
2. **Trait Composition**: Works alongside existing traits, no conflicts
3. **Zero Config**: Works with sensible defaults, configurable when needed
4. **Progressive Disclosure**: Minimizes context usage, loads only what's needed
5. **Fluent API**: Chainable methods for excellent DX
6. **Spec Compliant**: Follows agentskills.io specification exactly
7. **Fully Tested**: Comprehensive test coverage with clear fixtures

## Resources

- **agentskills.io Specification**: https://agentskills.io
- **Tutorial**: `tutorial/agent-skills-system.md`
- **Example Skills**: `examples/skills/`
- **Tests**: `tests/Unit/Skills/`, `tests/Feature/SkillIntegrationTest.php`

---

**Built with ❤️ for Laragentic**
