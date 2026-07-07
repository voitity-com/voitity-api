<?php

return [
    'default' => 'custom',
    'version' => '2026-07',
    'templates' => [
        'custom' => [
            'key' => 'custom',
            'label' => 'Custom profile',
            'description' => 'Flexible profile for any person or public role.',
            'source_types' => ['cv', 'manual', 'website', 'instagram'],
            'sections' => [
                ['key' => 'me', 'label' => 'About', 'required' => true],
                ['key' => 'highlights', 'label' => 'Highlights', 'required' => false],
                ['key' => 'work', 'label' => 'Work', 'required' => false],
                ['key' => 'projects', 'label' => 'Projects', 'required' => false],
            ],
            'quality_rules' => [
                ['key' => 'description', 'label' => 'Profile description', 'type' => 'profile_field', 'field' => 'description', 'min_length' => 120, 'weight' => 2],
                ['key' => 'personality', 'label' => 'Personality', 'type' => 'profile_field', 'field' => 'personality', 'min_length' => 60, 'weight' => 1],
                ['key' => 'about', 'label' => 'About section', 'type' => 'data_section', 'section' => 'me', 'min_items' => 1, 'weight' => 1],
                ['key' => 'source', 'label' => 'Reviewed source', 'type' => 'source_type', 'source_type' => 'cv', 'min_items' => 1, 'weight' => 1],
            ],
            'interview_questions' => [
                'Tell me about your background and current focus.',
                'What should visitors ask this profile about?',
                'Which links or sources should the profile use when it needs more detail?',
            ],
        ],
        'developer' => [
            'key' => 'developer',
            'label' => 'Developer',
            'description' => 'Technical profile for software engineers, builders, and technology leads.',
            'source_types' => ['cv', 'manual', 'website'],
            'sections' => [
                ['key' => 'me', 'label' => 'Technical summary', 'required' => true],
                ['key' => 'work', 'label' => 'Experience', 'required' => true],
                ['key' => 'projects', 'label' => 'Projects', 'required' => true],
                ['key' => 'skills', 'label' => 'Skills', 'required' => true],
                ['key' => 'education', 'label' => 'Education', 'required' => false],
            ],
            'quality_rules' => [
                ['key' => 'description', 'label' => 'Technical summary', 'type' => 'profile_field', 'field' => 'description', 'min_length' => 120, 'weight' => 2],
                ['key' => 'experience', 'label' => 'Experience facts', 'type' => 'fact_category', 'category' => 'experience', 'min_items' => 1, 'weight' => 2],
                ['key' => 'projects', 'label' => 'Project facts', 'type' => 'fact_category', 'category' => 'projects', 'min_items' => 1, 'weight' => 2],
                ['key' => 'skills', 'label' => 'Skills facts', 'type' => 'fact_category', 'category' => 'skills', 'min_items' => 1, 'weight' => 1],
                ['key' => 'cv', 'label' => 'Reviewed CV source', 'type' => 'source_type', 'source_type' => 'cv', 'min_items' => 1, 'weight' => 1],
            ],
            'interview_questions' => [
                'What systems have you built recently and what was your role?',
                'Which stack do you use most confidently?',
                'Describe a project where you improved performance, reliability, or delivery speed.',
                'How do you approach testing and production incidents?',
            ],
        ],
        'model' => [
            'key' => 'model',
            'label' => 'Model',
            'description' => 'Portfolio profile for models, creators, actors, and visual talent.',
            'source_types' => ['cv', 'manual', 'website', 'instagram'],
            'sections' => [
                ['key' => 'me', 'label' => 'Bio', 'required' => true],
                ['key' => 'portfolio', 'label' => 'Portfolio', 'required' => true],
                ['key' => 'campaigns', 'label' => 'Campaigns', 'required' => false],
                ['key' => 'measurements', 'label' => 'Measurements', 'required' => false],
                ['key' => 'availability', 'label' => 'Availability', 'required' => false],
            ],
            'quality_rules' => [
                ['key' => 'description', 'label' => 'Bio', 'type' => 'profile_field', 'field' => 'description', 'min_length' => 100, 'weight' => 2],
                ['key' => 'portfolio', 'label' => 'Portfolio facts', 'type' => 'fact_category', 'category' => 'portfolio', 'min_items' => 1, 'weight' => 2],
                ['key' => 'campaigns', 'label' => 'Campaign or work facts', 'type' => 'fact_category', 'category' => 'experience', 'min_items' => 1, 'weight' => 1],
                ['key' => 'instagram', 'label' => 'Instagram link', 'type' => 'network', 'network' => 'instagram', 'weight' => 1],
                ['key' => 'source', 'label' => 'Reviewed source', 'type' => 'source_type', 'source_type' => 'cv', 'min_items' => 1, 'weight' => 1],
            ],
            'interview_questions' => [
                'What type of modeling or visual work do you focus on?',
                'Which campaigns, shoots, or collaborations should visitors know about?',
                'Where can people see the full portfolio?',
                'What booking, location, or availability details are public?',
            ],
        ],
        'creator' => [
            'key' => 'creator',
            'label' => 'Creator',
            'description' => 'Profile for content creators, educators, streamers, and public personalities.',
            'source_types' => ['manual', 'website', 'instagram'],
            'sections' => [
                ['key' => 'me', 'label' => 'Bio', 'required' => true],
                ['key' => 'topics', 'label' => 'Topics', 'required' => true],
                ['key' => 'channels', 'label' => 'Channels', 'required' => true],
                ['key' => 'collaborations', 'label' => 'Collaborations', 'required' => false],
            ],
            'quality_rules' => [
                ['key' => 'description', 'label' => 'Bio', 'type' => 'profile_field', 'field' => 'description', 'min_length' => 100, 'weight' => 2],
                ['key' => 'topics', 'label' => 'Topics facts', 'type' => 'fact_category', 'category' => 'topics', 'min_items' => 1, 'weight' => 1],
                ['key' => 'social', 'label' => 'At least one social link', 'type' => 'network_any', 'weight' => 1],
            ],
            'interview_questions' => [
                'What content topics define this profile?',
                'Which platforms should visitors follow?',
                'What collaborations or offers are relevant?',
            ],
        ],
    ],
];
