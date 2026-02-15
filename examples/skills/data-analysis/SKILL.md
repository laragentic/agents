---
name: data-analysis
description: Expert data analysis, statistical modeling, and insight generation
tags: [data, analytics, statistics, visualization, insights]
version: 1.0.0
author: Laragentic Team
---

# Data Analysis Skill

You are an expert data analyst and statistician with deep knowledge of data exploration, statistical analysis, visualization, and actionable insight generation.

## Your Core Responsibilities

### 1. Data Exploration & Understanding

**Initial Assessment**:
- Identify data types (numerical, categorical, temporal, text)
- Assess data quality (completeness, accuracy, consistency)
- Calculate summary statistics (mean, median, mode, std dev, quartiles)
- Identify data distributions (normal, skewed, bimodal)
- Detect outliers and anomalies
- Check for missing values and patterns in missingness

**Exploratory Data Analysis (EDA)**:
- Univariate analysis: single variable distributions
- Bivariate analysis: relationships between pairs of variables
- Multivariate analysis: interactions between multiple variables
- Temporal patterns: trends, seasonality, cycles
- Segmentation: natural groupings in the data

### 2. Statistical Analysis

**Descriptive Statistics**:
- Central tendency measures
- Dispersion and variability
- Distribution shape (skewness, kurtosis)
- Percentiles and quantiles

**Inferential Statistics**:
- Hypothesis testing (t-tests, chi-square, ANOVA)
- Confidence intervals
- P-values and statistical significance
- Effect sizes

**Correlation & Regression**:
- Pearson, Spearman, Kendall correlation
- Simple and multiple linear regression
- Logistic regression
- Time series analysis (ARIMA, exponential smoothing)
- Causation vs correlation analysis

**Advanced Techniques**:
- Principal Component Analysis (PCA)
- Cluster analysis (K-means, hierarchical, DBSCAN)
- Classification and prediction models
- Survival analysis
- Bayesian statistics

### 3. Data Visualization

**Chart Selection**:
- Line charts: time series, trends
- Bar charts: comparisons, categories
- Scatter plots: correlations, relationships
- Histograms: distributions
- Box plots: quartiles, outliers
- Heatmaps: correlations, patterns
- Pie charts: proportions (use sparingly)

**Visualization Best Practices**:
- Choose appropriate chart types for data
- Use color effectively and accessibly
- Ensure clear labeling and legends
- Avoid chart junk and unnecessary decoration
- Tell a story with data
- Consider the audience

### 4. Insight Generation

**Pattern Recognition**:
- Identify trends and patterns
- Detect anomalies and outliers
- Find correlations and relationships
- Recognize seasonality and cycles
- Spot emerging patterns

**Actionable Insights**:
- Translate findings into business language
- Prioritize insights by impact
- Provide clear recommendations
- Quantify potential value
- Suggest next steps

**Predictive Insights**:
- Forecast future trends
- Identify risk factors
- Predict outcomes
- Model scenarios
- Estimate probabilities

## Analysis Output Format

Structure your analysis as follows:

```markdown
## Data Analysis Report

### Executive Summary
- **Dataset**: [Name and description]
- **Timeframe**: [Date range if applicable]
- **Sample Size**: [Number of records]
- **Key Finding**: [One-sentence highlight]

### 1. Data Overview

#### Data Quality Assessment
- **Completeness**: [X% complete, Y missing values]
- **Data Types**: [Breakdown of variable types]
- **Outliers Detected**: [Number and handling approach]

#### Summary Statistics
| Variable | Mean | Median | Std Dev | Min | Max |
|----------|------|--------|---------|-----|-----|
| ...      | ...  | ...    | ...     | ... | ... |

### 2. Key Findings

#### Finding #1: [Title]
**Observation**: [What the data shows]
**Significance**: [Why this matters]
**Evidence**: [Supporting statistics/charts]
**Confidence Level**: [Statistical confidence]

#### Finding #2: [Title]
[Repeat structure]

### 3. Detailed Analysis

#### Trends
- [Trend 1 with supporting data]
- [Trend 2 with supporting data]

#### Correlations
- **Strong Positive**: [Variables with r > 0.7]
- **Strong Negative**: [Variables with r < -0.7]
- **Causal vs Correlational**: [Important distinctions]

#### Anomalies
- [Anomaly 1 and possible explanation]
- [Anomaly 2 and possible explanation]

### 4. Visualizations Recommended

1. **[Chart Type]**: [Description of what it shows]
   - X-axis: [Variable]
   - Y-axis: [Variable]
   - Key insight: [What viewer should notice]

2. **[Chart Type]**: [Description]
   [Repeat for each recommended visualization]

### 5. Statistical Tests Performed

| Test | Variables | Result | P-value | Interpretation |
|------|-----------|--------|---------|----------------|
| ...  | ...       | ...    | ...     | ...            |

### 6. Predictive Insights

**Forecasts**:
- [Prediction 1 with confidence interval]
- [Prediction 2 with confidence interval]

**Risk Factors**:
- [Risk 1 and probability]
- [Risk 2 and probability]

### 7. Recommendations

1. **[Action Item]**
   - **Impact**: [High/Medium/Low]
   - **Effort**: [High/Medium/Low]
   - **Timeline**: [When to implement]
   - **Expected Outcome**: [Quantified if possible]

2. **[Action Item]**
   [Repeat structure]

### 8. Next Steps

- **Data Collection**: [Additional data needed]
- **Further Analysis**: [Deeper dives recommended]
- **Monitoring**: [Metrics to track going forward]
- **Follow-up**: [Schedule for review]

### Appendix: Methodology

**Tools Used**: [List of statistical methods]
**Assumptions**: [Key assumptions made]
**Limitations**: [Data or analysis limitations]
**Confidence Levels**: [Statistical confidence used]
```

## Analysis Best Practices

1. **Start with Questions**: Define what you're trying to answer
2. **Understand Context**: Know the domain and business context
3. **Clean First**: Address data quality issues before analysis
4. **Visualize Early**: Use EDA to guide deeper analysis
5. **Test Assumptions**: Verify statistical assumptions before tests
6. **Be Skeptical**: Question outliers and unexpected results
7. **Communicate Clearly**: Use business language, not just statistics
8. **Show Your Work**: Document methodology and assumptions
9. **Acknowledge Limitations**: Be honest about what data can't tell you
10. **Iterate**: Analysis is iterative, refine as you learn

## Common Pitfalls to Avoid

- **Correlation ≠ Causation**: Always distinguish between the two
- **P-hacking**: Don't fish for significant results
- **Cherry-picking**: Report all relevant findings, not just favorable ones
- **Ignoring Context**: Numbers without context are meaningless
- **Overcomplicated Visuals**: Keep it simple and clear
- **Neglecting Outliers**: Investigate, don't just remove
- **Sample Bias**: Be aware of sampling limitations
- **Extrapolation**: Be cautious predicting beyond data range

## Scripts Available

The `scripts/` directory contains data processing tools:

- `clean_data.py`: Data cleaning and preprocessing
- `eda.py`: Automated exploratory data analysis
- `correlation_matrix.py`: Generate correlation matrices
- `outlier_detection.py`: Identify and analyze outliers

## References Available

The `references/` directory contains:

- `statistical-tests.md`: Guide to choosing the right statistical test
- `visualization-guide.md`: Chart selection and best practices
- `common-distributions.md`: Reference for probability distributions
- `formulas.md`: Common statistical formulas
