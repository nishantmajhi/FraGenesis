#!/usr/bin/env perl
use strict;
use warnings;
use utf8;
use JSON::PP;
use File::Basename qw(dirname);
use Cwd qw(abs_path);

binmode STDOUT, ':utf8';

# ── Locate the directory this script lives in ──────────────────────────────
my $dir = dirname( abs_path($0) );

# ── Process all 7 units ────────────────────────────────────────────────────
for my $n ( 1 .. 7 ) {
    my $txt  = "$dir/raw/12-$n.txt";
    my $json = "$dir/12-$n.json";

    unless ( -f $txt ) {
        warn "[!] $txt not found – skipping\n";
        next;
    }

    my $data = parse_unit($txt);

    open my $fh, '>:utf8', $json
        or die "Cannot write $json: $!\n";
    print $fh JSON::PP->new->utf8(0)->pretty->canonical->encode($data);
    close $fh;

    my $mcq = scalar @{ $data->{multipleChoice}{questions} };
    my $saq = scalar @{ $data->{shortAnswerQuestions}{questions} };
    my $laq = scalar @{ $data->{longAnswerQuestions}{questions} };
    my $sn  = scalar @{ $data->{shortNotes}{topics} };
    printf "12-%d.json  -  MCQ: %d  SAQ: %d  LAQ: %d  ShortNotes: %d\n",
        $n, $mcq, $saq, $laq, $sn;
}

print "\nAll JSON files written to $dir\n";


# ═══════════════════════════════════════════════════════════════════════════
# parse_unit($filepath) → \%data
# ═══════════════════════════════════════════════════════════════════════════
sub parse_unit {
    my ($file) = @_;

    open my $fh, '<:utf8', $file
        or die "Cannot open $file: $!\n";

    # ── State ──────────────────────────────────────────────────────────────
    my $title    = '';
    my @topics   = ();
    my $section  = '';          # topics | mcq | saq | laq | sn
    my $mcq_marks = 1;
    my $saq_marks = 5;
    my $laq_marks = 8;
    my $sn_marks  = 2.5;
    my @mcq_qs   = ();
    my @saq_qs   = ();
    my @laq_qs   = ();
    my @sn_topics = ();

    my $cur = undef;   # hashref of the question/note being built

    # Helper: save the accumulated $cur into the right array
    my $flush = sub {
        return unless defined $cur;
        if    ( $section eq 'mcq' ) { push @mcq_qs,    {%$cur} }
        elsif ( $section eq 'saq' ) { push @saq_qs,    {%$cur} }
        elsif ( $section eq 'laq' ) { push @laq_qs,    {%$cur} }
        elsif ( $section eq 'sn'  ) { push @sn_topics, {%$cur} }
        $cur = undef;
    };

    while ( my $raw = <$fh> ) {
        chomp $raw;

        # ── 1. TITLE ──────────────────────────────────────────────────────
        if ( $raw =~ /^TITLE:\s*(.+)$/ ) {
            $title = trim($1);
            next;
        }

        # ── 2. TOPICS block start ─────────────────────────────────────────
        if ( $raw =~ /^TOPICS:\s*$/ ) {
            $section = 'topics';
            next;
        }

        # ── 3. Section headers ────────────────────────────────────────────
        if ( $raw =~ /^\[MCQ\s+([\d.]+)\]\s*$/ ) {
            $flush->();
            $section   = 'mcq';
            $mcq_marks = $1 + 0;
            next;
        }
        if ( $raw =~ /^\[SAQ\s+([\d.]+)\]\s*$/ ) {
            $flush->();
            $section   = 'saq';
            $saq_marks = $1 + 0;
            next;
        }
        if ( $raw =~ /^\[LAQ\s+([\d.]+)\]\s*$/ ) {
            $flush->();
            $section   = 'laq';
            $laq_marks = $1 + 0;
            next;
        }
        if ( $raw =~ /^\[SN\s+([\d.]+)\]\s*$/ ) {
            $flush->();
            $section  = 'sn';
            $sn_marks = $1 + 0;
            next;
        }

        # ── 4. Topics list (one topic per line) ───────────────────────────
        if ( $section eq 'topics' ) {
            my $t = trim($raw);
            push @topics, $t if $t ne '';
            next;
        }

        # ── 5. Blank lines: flush current question ────────────────────────
        if ( $raw =~ /^\s*$/ ) {
            $flush->();
            next;
        }

        # ── 6. MCQ section ────────────────────────────────────────────────
        if ( $section eq 'mcq' ) {

            # New question line:  Q3 [1.4]: question text
            if ( $raw =~ /^Q(\d+)\s+\[([^\]]+)\]:\s*(.+)$/ ) {
                $flush->();
                $cur = {
                    id       => $1 + 0,
                    topic    => trim($2),
                    question => trim($3),
                    options  => [],
                };
                next;
            }

            # Option line (2-space indent + letter):  "  a. Create"
            if ( defined $cur && $raw =~ /^  ([a-d])\.\s*(.*)$/ ) {
                my ( $letter, $text ) = ( $1, trim($2) );
                push @{ $cur->{options} }, "$letter. $text";
                next;
            }

            # Continuation of question text (any other indented line)
            if ( defined $cur && $raw =~ /^  (.+)$/ ) {
                $cur->{question} .= ' ' . trim($1);
                next;
            }
        }

        # ── 7. SAQ / LAQ section ──────────────────────────────────────────
        if ( $section eq 'saq' || $section eq 'laq' ) {

            # New question line:  Q5 [1.6]: question text
            if ( $raw =~ /^Q(\d+)\s+\[([^\]]+)\]:\s*(.+)$/ ) {
                $flush->();
                $cur = {
                    id       => $1 + 0,
                    topic    => trim($2),
                    question => trim($3),
                };
                next;
            }

            # Continuation of question text (2-space indent)
            if ( defined $cur && $raw =~ /^  (.+)$/ ) {
                $cur->{question} .= ' ' . trim($1);
                next;
            }
        }

        # ── 8. SN section ─────────────────────────────────────────────────
        if ( $section eq 'sn' ) {

            # Short-note line:  N3 [1.2]: ER diagram
            if ( $raw =~ /^N(\d+)\s+\[([^\]]+)\]:\s*(.+)$/ ) {
                $flush->();                    # flush any previous entry
                $cur = {
                    id    => $1 + 0,
                    topic => trim($2),
                    name  => trim($3),
                };
                $flush->();                    # SN entries have no continuation
                next;
            }
        }
    }

    $flush->();    # flush whatever is still pending at EOF
    close $fh;

    return {
        title => $title,
        multipleChoice => {
            marks     => $mcq_marks + 0,
            questions => \@mcq_qs,
        },
        shortAnswerQuestions => {
            marks     => $saq_marks + 0,
            questions => \@saq_qs,
        },
        longAnswerQuestions => {
            marks     => $laq_marks + 0,
            questions => \@laq_qs,
        },
        shortNotes => {
            marks  => $sn_marks + 0,
            topics => \@sn_topics,
        },
    };
}


# ── trim leading/trailing whitespace ──────────────────────────────────────
sub trim {
    my ($s) = @_;
    $s =~ s/^\s+|\s+$//gr;
}