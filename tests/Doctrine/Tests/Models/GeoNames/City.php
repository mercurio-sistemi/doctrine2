<?php

namespace Doctrine\Tests\Models\GeoNames;

/**
 * @Entity
 * @Table(name="geonames_city")
 * @Cache
 */
class City
{
    /**
     * @Id
     * @Column(type="string", length=25)
     * @GeneratedValue(strategy="NONE")
     */
    protected $id;

    /**
     * @ManyToOne(targetEntity="Country")
     * @JoinColumn(name="country", referencedColumnName="id")
     * @Cache
     */
    protected $country;

    /**
     * @ManyToOne(targetEntity="Admin1")
     * @JoinColumns({
     *   @JoinColumn(name="admin1", referencedColumnName="id"),
     *   @JoinColumn(name="country", referencedColumnName="country")
     * })
     * @Cache
     */
    protected $admin1;

    /**
     * @Column(type="string", length=255);
     */
    protected $name;


    public function __construct($id, $name)
    {
        $this->id = $id;
        $this->name = $name;
    }

    public function getId()
    {
        return $this->id;
    }

    public function getCountry()
    {
        return $this->country;
    }

    public function getAdmin1()
    {
        return $this->admin1;
    }

    public function getName()
    {
        return $this->name;
    }
}
