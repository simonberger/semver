<?php

/*
 * This file is part of composer/semver.
 *
 * (c) Composer <https://github.com/composer>
 *
 * For the full copyright and license information, please view
 * the LICENSE file that was distributed with this source code.
 */

namespace Composer\Semver;

use Composer\Semver\Constraint\Constraint;
use Composer\Semver\Constraint\ConstraintInterface;
use Composer\Semver\Constraint\MatchAllConstraint;
use Composer\Semver\Constraint\MatchNoneConstraint;
use Composer\Semver\Constraint\AnyDevConstraint;
use Composer\Semver\Constraint\MultiConstraint;

/**
 * Helper class to evaluate constraint by compiling and reusing the code to evaluate
 */
class Intervals
{
    /**
     * @phpstan-var array<string, array{'numeric': Interval[], 'branches': array<AnyDevConstraint|Constraint>}>
     */
    private static $intervalsCache = array();

    /**
     * @phpstan-var array<string, int>
     */
    private static $opSortOrder = array(
        '>=' => -3,
        '<' => -2,
        '>' => 2,
        '<=' => 3,
    );

    /**
     * Clears the memoization cache once you are done
     *
     * @return void
     */
    public static function clear()
    {
        self::$intervalsCache = array();
    }

    /**
     * Checks whether $candidate is a subset of $constraint
     *
     * @return bool
     */
    public static function isSubsetOf(ConstraintInterface $candidate, ConstraintInterface $constraint)
    {
        if ($constraint instanceof MatchAllConstraint) {
            return true;
        }

        if ($candidate instanceof MatchNoneConstraint || $constraint instanceof MatchNoneConstraint) {
            return false;
        }

        $intersectionIntervals = self::get(new MultiConstraint(array($candidate, $constraint), true));
        $candidateIntervals = self::get($candidate);
        if (\count($intersectionIntervals['numeric']) !== \count($candidateIntervals['numeric'])) {
            return false;
        }

        foreach ($intersectionIntervals['numeric'] as $index => $interval) {
            if (!isset($candidateIntervals['numeric'][$index])) {
                return false;
            }

            if ((string) $candidateIntervals['numeric'][$index]->getStart() !== (string) $interval->getStart()) {
                return false;
            }

            if ((string) $candidateIntervals['numeric'][$index]->getEnd() !== (string) $interval->getEnd()) {
                return false;
            }
        }

        if (\count($intersectionIntervals['branches']) !== \count($candidateIntervals['branches'])) {
            return false;
        }
        foreach ($intersectionIntervals['branches'] as $index => $c) {
            if ((string) $c !== (string) $candidateIntervals['branches'][$index]) {
                return false;
            }
        }

        return true;
    }

    /**
     * Checks whether $a and $b have any intersection, equivalent to $a->matches($b)
     *
     * @return bool
     */
    public static function haveIntersections(ConstraintInterface $a, ConstraintInterface $b)
    {
        if ($a instanceof MatchAllConstraint || $b instanceof MatchAllConstraint) {
            return true;
        }

        if ($a instanceof MatchNoneConstraint || $b instanceof MatchNoneConstraint) {
            return false;
        }

        $intersectionIntervals = self::generateIntervals(new MultiConstraint(array($a, $b), true), true);

        return \count($intersectionIntervals['numeric']) > 0 || \count($intersectionIntervals['branches']) > 0;
    }

    /**
     * Attempts to optimize a MultiConstraint
     *
     * When merging MultiConstraints together they can get very large, this will
     * compact it by looking at the real intervals covered by all the constraints
     * and then creates a new constraint containing only the smallest amount of rules
     * to match the same intervals.
     *
     * @return ConstraintInterface
     */
    public static function compactConstraint(ConstraintInterface $constraint)
    {
        if (!$constraint instanceof MultiConstraint) {
            return $constraint;
        }

        $intervals = self::generateIntervals($constraint);
        $constraints = array();
        $hasNumericMatchAll = false;
        $isConjunctive = false;

        $count = \count($intervals['numeric']);
        // attempt to convert back 0 - <x + >x - +inf to != x as long as we only have some of those, otherwise bail out of this optimization
        if ($count > 1 && (string) $intervals['numeric'][0]->getStart() === (string) Interval::zero() && (string) $intervals['numeric'][$count-1]->getEnd() === (string) Interval::positiveInfinity()) {
            $isConjunctive = true;
            for ($i = 0; $i < $count-1; $i++) {
                $interval = $intervals['numeric'][$i];
                if ($interval->getEnd()->getVersion() === $intervals['numeric'][$i+1]->getStart()->getVersion() && $interval->getEnd()->getOperator() === '<' && $intervals['numeric'][$i+1]->getStart()->getOperator() === '>') {
                    $constraints[] = new Constraint('!=', $interval->getEnd()->getVersion());
                    continue;
                }

                $constraints = array();
                $isConjunctive = false;
                break;
            }
        }

        if (!$isConjunctive) {
            for ($i = 0, $count = \count($intervals['numeric']); $i < $count; $i++) {
                $interval = $intervals['numeric'][$i];
                if ((string) $interval->getStart() === (string) Interval::zero() && (string) $interval->getEnd() === (string) Interval::positiveInfinity()) {
                    $constraints[] = $interval->getStart();
                    $hasNumericMatchAll = true;
                    break;
                }

                // convert back >= x - <= x intervals to == x
                if ($interval->getStart()->getVersion() === $interval->getEnd()->getVersion() && $interval->getStart()->getOperator() === '>=' && $interval->getEnd()->getOperator() === '<=') {
                    $constraints[] = new Constraint('==', $interval->getStart()->getVersion());
                    continue;
                }

                if ((string) $interval->getStart() === (string) Interval::zero()) {
                    $constraints[] = $interval->getEnd();
                } elseif ((string) $interval->getEnd() === (string) Interval::positiveInfinity()) {
                    $constraints[] = $interval->getStart();
                } else {
                    $constraints[] = new MultiConstraint(array($interval->getStart(), $interval->getEnd()), true);
                }
            }
        }

        $devConstraints = array();
        $allDevConstraintsAreNegations = true;
        foreach ($intervals['branches'] as $branchConstraint) {
            if ($branchConstraint instanceof AnyDevConstraint) {
                if ($hasNumericMatchAll) {
                    return new MatchAllConstraint;
                }

                // if we matched != x then no need to add a constraint matching all branches
                if ($isConjunctive) {
                    continue;
                }
            }

            if ($branchConstraint->getOperator() !== '!=') {
                $allDevConstraintsAreNegations = false;
            }
            $devConstraints[] = $branchConstraint;
        }

        if (\count($devConstraints) === 0) {
            // noop
        } elseif ($allDevConstraintsAreNegations && !$isConjunctive) {
            if (\count($devConstraints) > 1) {
                $devConstraint = new MultiConstraint($devConstraints, true);
            } else {
                $devConstraint = $devConstraints[0];
            }

            if (\count($constraints) === 1 && (string) $constraints[0] === (string) Interval::zero()) {
                return $devConstraint;
            }

            $constraints[] = $devConstraint;
        } else {
            $constraints = array_merge($constraints, $devConstraints);
        }

        if (\count($constraints) > 1) {
            return new MultiConstraint($constraints, $isConjunctive);
        }

        if (\count($constraints) === 1) {
            return $constraints[0];
        }

        return new MatchNoneConstraint;
    }

    /**
     * Creates an array of numeric intervals and branch constraints representing a given constraint
     *
     * if the returned numeric array is empty it means the constraint matches nothing in the numeric range (0 - +inf)
     * if the returned branches array is empty it means no dev-* versions are matched
     * if a constraint matches all possible dev-* versions, branches will contain Interval::anyDev() as a constraint
     *
     * @return array
     * @phpstan-return array{'numeric': Interval[], 'branches': array<AnyDevConstraint|Constraint>}
     */
    public static function get(ConstraintInterface $constraint)
    {
        $key = (string) $constraint;

        if (!isset(self::$intervalsCache[$key])) {
            self::$intervalsCache[$key] = self::generateIntervals($constraint);
        }

        return self::$intervalsCache[$key];
    }

    /**
     * @phpstan-return array{'numeric': Interval[], 'branches': array<AnyDevConstraint|Constraint>}
     */
    private static function generateIntervals(ConstraintInterface $constraint, $stopOnFirstValidInterval = false)
    {
        if ($constraint instanceof MatchAllConstraint) {
            return array('numeric' => array(new Interval(Interval::zero(), Interval::positiveInfinity())), 'branches' => array(Interval::anyDev()));
        }

        if ($constraint instanceof MatchNoneConstraint) {
            return array('numeric' => array(), 'branches' => array());
        }

        if ($constraint instanceof Constraint) {
            return self::generateSingleConstraintIntervals($constraint);
        }

        if ($constraint instanceof AnyDevConstraint) {
            return array('numeric' => array(), 'branches' => array($constraint));
        }

        if (!$constraint instanceof MultiConstraint) {
            throw new \UnexpectedValueException('The constraint passed in should be an MatchAllConstraint, Constraint or MultiConstraint instance, got '.get_class($constraint).'.');
        }

        $constraints = $constraint->getConstraints();

        $numericGroups = array();
        $branchesGroups = array();
        foreach ($constraints as $c) {
            $res = self::get($c);
            $numericGroups[] = $res['numeric'];
            $branchesGroups[] = $res['branches'];
        }

        $branchConstraints = array();
        if ($constraint->isDisjunctive()) {
            $disallowlist = array();
            foreach ($branchesGroups as $i => $group) {
                foreach ($group as $j => $c) {
                    // if any of the groups matches all that overrules everything else
                    if ($c instanceof AnyDevConstraint) {
                        $branchConstraints = array($c);
                        break 2;
                    }

                    // all == constraints are kept as long as no != constraint exists in another group, in which case it gets unset later
                    if ($c instanceof Constraint && $c->getOperator() === '==') {
                        $branchConstraints[(string) $c] = $c;
                        continue;
                    }

                    $otherGroupMatches = 0;
                    foreach ($branchesGroups as $i2 => $group2) {
                        if ($i2 === $i) {
                            continue;
                        }

                        // empty groups without dev constraints should not constrain what is returned by those with dev constraints
                        if (\count($group2) === 0) {
                            $otherGroupMatches++;
                            continue;
                        }

                        foreach ($group2 as $j2 => $c2) {
                            // same constraint found, ignore it
                            if ((string) $c2 === (string) $c) {
                                $otherGroupMatches++;
                                continue;
                            }

                            // != x || == x turns into *
                            if (
                                // implicitly true: $c instanceof Constraint && $c->getOperator() === '!='
                                $c2 instanceof Constraint && $c2->getOperator() === '=='
                                && $c->getVersion() === $c2->getVersion()
                            ) {
                                return array('numeric' => array(new Interval(Interval::zero(), Interval::positiveInfinity())), 'branches' => array(new AnyDevConstraint));
                            }

                            // != x || != y turns into *, but only if a single constraint on each side
                            if (
                                // implicitly true: $c instanceof Constraint && $c->getOperator() === '!='
                                $c2 instanceof Constraint && $c2->getOperator() === '!='
                                && \count($branchesGroups[$i]) === 1 && \count($branchesGroups[$i2]) === 1
                                && $c->getVersion() !== $c2->getVersion()
                            ) {
                                return array('numeric' => array(new Interval(Interval::zero(), Interval::positiveInfinity())), 'branches' => array(new AnyDevConstraint));
                            }

                            // != x || == y turns into != x
                            if (
                                // implicitly true: $c instanceof Constraint && $c->getOperator() === '!='
                                $c2 instanceof Constraint && $c2->getOperator() === '=='
                                && $c->getVersion() !== $c2->getVersion()
                            ) {
                                $otherGroupMatches++;
                                $disallowlist[(string) $c2] = true;
                            }
                        }
                    }

                    // only keep != constraints which appear in all sub-constraints
                    if ($otherGroupMatches >= \count($branchesGroups) - 1) {
                        $branchConstraints[(string) $c] = $c;
                    }
                }
            }
            foreach ($disallowlist as $c => $dummy) {
                unset($branchConstraints[$c]);
            }
        } else {
            $disallowlist = array();
            foreach ($branchesGroups as $i => $group) {
                foreach ($group as $j => $c) {
                    $otherGroupMatches = 0;
                    foreach ($branchesGroups as $i2 => $group2) {
                        if ($i2 === $i) {
                            continue;
                        }

                        foreach ($group2 as $j2 => $c2) {
                            if (
                                // any constraint matches itself
                                (string) $c2 === (string) $c
                                // any constraint matches dev*
                                || $c2 instanceof AnyDevConstraint
                                // != dev-* + != dev-* matches as long as they don't get unset later
                                || ($c instanceof Constraint && $c->getOperator() === '!=' && $c2->getOperator() === '!=')
                            ) {
                                $otherGroupMatches++;
                                continue;
                            }

                            if ($c instanceof AnyDevConstraint) {
                                continue;
                            }

                            // == x && != x cancel each other, make sure none of these appears in the output
                            if (
                                $c->getOperator() === '=='
                                && $c2->getOperator() === '!='
                                && $c->getVersion() === $c2->getVersion()
                            ) {
                                $disallowlist[(string) $c] = true;
                                $disallowlist[(string) $c2] = true;
                            }

                            // == x && != y turns into == x if != y group has a single constraint
                            if (
                                $c->getOperator() === '=='
                                && $c2->getOperator() === '!='
                                && $c->getVersion() !== $c2->getVersion()
                            ) {
                                $otherGroupMatches++;
                                $disallowlist[(string) $c2] = true;
                            }
                        }
                    }

                    // only keep constraints which appear in all conjunctive sub-constraints
                    if ($otherGroupMatches >= \count($branchesGroups) - 1) {
                        $branchConstraints[(string) $c] = $c;
                    }
                }
            }
            foreach ($disallowlist as $c => $dummy) {
                unset($branchConstraints[$c]);
            }
        }

        $branchConstraints = array_values($branchConstraints);

        if (count($numericGroups) === 1) {
            return array('numeric' => $numericGroups[0], 'branches' => $branchConstraints);
        }

        $borders = array();
        foreach ($numericGroups as $group) {
            foreach ($group as $interval) {
                $borders[] = array('version' => $interval->getStart()->getVersion(), 'operator' => $interval->getStart()->getOperator(), 'side' => 'start');
                $borders[] = array('version' => $interval->getEnd()->getVersion(), 'operator' => $interval->getEnd()->getOperator(), 'side' => 'end');
            }
        }

        $opSortOrder = self::$opSortOrder;
        usort($borders, function ($a, $b) use ($opSortOrder) {
            $order = version_compare($a['version'], $b['version']);
            if ($order === 0) {
                return $opSortOrder[$a['operator']] - $opSortOrder[$b['operator']];
            }

            return $order;
        });

        $activeIntervals = 0;
        $intervals = array();
        $index = 0;
        $activationThreshold = $constraint->isConjunctive() ? \count($numericGroups) : 1;
        $active = false;
        $start = null;
        foreach ($borders as $border) {
            if ($border['side'] === 'start') {
                $activeIntervals++;
            } else {
                $activeIntervals--;
            }
            if (!$active && $activeIntervals >= $activationThreshold) {
                $start = new Constraint($border['operator'], $border['version']);
                $active = true;
            }
            if ($active && $activeIntervals < $activationThreshold) {
                $active = false;

                // filter out invalid intervals like > x - <= x, or >= x - < x
                if (
                    version_compare($start->getVersion(), $border['version'], '=')
                    && (
                        ($start->getOperator() === '>' && $border['operator'] === '<=')
                        || ($start->getOperator() === '>=' && $border['operator'] === '<')
                    )
                ) {
                    unset($intervals[$index]);
                } else {
                    $intervals[$index] = new Interval($start, new Constraint($border['operator'], $border['version']));
                    $index++;

                    if ($stopOnFirstValidInterval) {
                        break;
                    }
                }

                $start = null;
            }
        }

        return array('numeric' => $intervals, 'branches' => $branchConstraints);
    }

    /**
     * @phpstan-return array{'numeric': Interval[], 'branches': array<AnyDevConstraint|Constraint>}
     */
    private static function generateSingleConstraintIntervals(Constraint $constraint)
    {
        $op = $constraint->getOperator();

        // handle branch constraints first
        if (substr($constraint->getVersion(), 0, 4) === 'dev-') {
            $intervals = array();

            // != dev-foo means any numeric version may match
            if ($op === '!=') {
                $intervals[] = new Interval(Interval::zero(), Interval::positiveInfinity());
            }

            return array('numeric' => $intervals, 'branches' => array($constraint));
        }

        if ($op[0] === '>') { // > & >=
            return array('numeric' => array(new Interval($constraint, Interval::positiveInfinity())), 'branches' => array());
        }
        if ($op[0] === '<') { // < & <=
            return array('numeric' => array(new Interval(Interval::zero(), $constraint)), 'branches' => array());
        }
        if ($op === '!=') {
            // convert !=x to intervals of 0 - <x && >x - +inf + dev*
            return array('numeric' => array(
                new Interval(Interval::zero(), new Constraint('<', $constraint->getVersion())),
                new Interval(new Constraint('>', $constraint->getVersion()), Interval::positiveInfinity()),
            ), 'branches' => array(Interval::anyDev()));
        }

        // convert ==x to an interval of >=x - <=x
        return array('numeric' => array(
            new Interval(new Constraint('>=', $constraint->getVersion()), new Constraint('<=', $constraint->getVersion())),
        ), 'branches' => array());
    }
}
